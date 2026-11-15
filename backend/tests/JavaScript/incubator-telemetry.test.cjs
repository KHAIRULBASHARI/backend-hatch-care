const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { readFileSync } = require('node:fs');
const context = { module: { exports: {} } };
vm.runInNewContext(readFileSync(require.resolve('../../public/js/incubator-telemetry.js'), 'utf8'), context);
const { create } = context.module.exports;

function fixture({ storage = null, startTime = 1000 } = {}) {
    let now = startTime;
    const elements = new Map();
    const document = { activeElement: null, getElementById(id) {
        if (!elements.has(id)) elements.set(id, { textContent: '', className: '', value: '' });
        return elements.get(id);
    }};
    const monitor = create({ document, now: () => now, staleMs: 15000, storage, storageKey: 'test-device' });
    return { monitor, el: id => document.getElementById(id), advance: ms => { now += ms; monitor.tick(); }, document };
}

test('only live MQTT telemetry marks device online and renders false and zero', () => {
    const { monitor, el } = fixture();
    monitor.telemetry({ temperature: 0, humidity: 0, heater: false, motor: false });
    assert.equal(el('tele-temp').textContent, '0.0');
    assert.equal(el('tele-hum').textContent, '0');
    assert.equal(el('tele-lamp-status').textContent, 'MATI (OFF)');
    assert.equal(el('tele-motor-status').textContent, 'STANDBY');
    assert.match(el('inc-device-badge').textContent, /Online.*MQTT/);
});

test('null and invalid sensors clear previous readings instead of keeping stale data', () => {
    const { monitor, el } = fixture();
    monitor.telemetry({ temperature: 37.7, humidity: 61 });
    monitor.telemetry({ temperature: null, temp: 37.7, humidity: null, temperatureValid: false, humidityValid: false });
    assert.equal(el('tele-temp').textContent, '--');
    assert.equal(el('tele-hum').textContent, '--');
    assert.match(el('telemetry-freshness').textContent, /Sensor DHT22 belum valid/);
});

test('disconnect, LWT offline and telemetry timeout keep last sensors explicitly stale', () => {
    const { monitor, el, advance } = fixture();
    const sample = { temperature: 37.7, humidity: 61, heater: true, motor: true };
    monitor.telemetry(sample);
    monitor.disconnected();
    assert.equal(el('tele-temp').textContent, '37.7');
    assert.equal(el('tele-hum').textContent, '61');
    assert.match(el('telemetry-freshness').textContent, /Data terakhir.*0 detik/);
    assert.equal(el('tele-lamp-status').textContent, 'TIDAK DIKETAHUI');
    monitor.telemetry(sample);
    advance(15001);
    assert.match(el('inc-device-badge').textContent, /terlambat/);
    assert.equal(el('tele-temp').textContent, '37.7');
    assert.match(el('telemetry-freshness').textContent, /Data terakhir.*15 detik/);
    assert.equal(el('tele-motor-status').textContent, 'TIDAK DIKETAHUI');
    monitor.telemetry(sample);
    monitor.status({ online: false });
    assert.match(el('inc-device-badge').textContent, /Offline/);
    assert.equal(el('tele-temp').textContent, '37.7');
});

test('retained telemetry and retained online status cannot impersonate a fresh sensor', () => {
    const { monitor, el } = fixture();
    monitor.status({ online: true }, true);
    monitor.telemetry({ temperature: 38, humidity: 60 }, true);
    assert.equal(el('tele-temp').textContent, '--');
    assert.doesNotMatch(el('inc-device-badge').textContent, /Online/);
});

test('saved MQTT readings survive reload but cannot claim live sensor or actuator state', () => {
    const memory = new Map();
    const storage = { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, value) };
    const first = fixture({ storage });
    first.monitor.telemetry({ temperature: 37.8, humidity: 58, heater: true, motor: false });
    const reloaded = fixture({ storage, startTime: 61000 });
    assert.equal(reloaded.el('tele-temp').textContent, '37.8');
    assert.equal(reloaded.el('tele-hum').textContent, '58');
    assert.match(reloaded.el('telemetry-freshness').textContent, /Data terakhir.*60 detik/);
    assert.doesNotMatch(reloaded.el('inc-device-badge').textContent, /Online/);
    assert.equal(reloaded.el('tele-lamp-status').textContent, 'TIDAK DIKETAHUI');
});

test('partial actuator packet does not erase sensors and does not freshen their age', () => {
    const { monitor, el, advance } = fixture();
    monitor.telemetry({ temperature: 37.8, humidity: 58 });
    advance(2000);
    monitor.telemetry({ motor: false });
    assert.equal(el('tele-temp').textContent, '37.8');
    assert.equal(el('tele-hum').textContent, '58');
    advance(13001);
    assert.match(el('telemetry-freshness').textContent, /Data terakhir.*15 detik/);
});

test('storage failures do not break live telemetry', () => {
    const storage = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('quota'); } };
    const { monitor, el } = fixture({ storage });
    assert.doesNotThrow(() => monitor.telemetry({ temperature: 37.8, humidity: 58 }));
    assert.equal(el('tele-temp').textContent, '37.8');
});

test('confirmed device settings update schedule but preserve an input being edited', () => {
    const { monitor, el, document } = fixture();
    el('input-temp-min').value = '36.5';
    document.activeElement = el('input-temp-min');
    monitor.telemetry({ temperature: 37, humidity: 60, tempMin: 37, tempMax: 38, lampMode: 'auto', motorIntervalHours: 4, motorDurationSeconds: 15, captureIntervalSeconds: 120, turnCountdownSec: 30 });
    assert.equal(el('input-temp-min').value, '36.5');
    assert.equal(el('tele-setpoint-range').textContent, '37.0 - 38.0');
    assert.match(el('tele-motor-info').textContent, /Tiap 4 jam \(15s\)/);
    assert.match(el('tele-motor-info').textContent, /30s/);
    assert.equal(el('capture-interval').value, 120);
});
