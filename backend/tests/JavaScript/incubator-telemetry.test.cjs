const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { readFileSync } = require('node:fs');
const context = { module: { exports: {} } };
vm.runInNewContext(readFileSync(require.resolve('../../public/js/incubator-telemetry.js'), 'utf8'), context);
const { create } = context.module.exports;

function fixture() {
    let now = 1000;
    const elements = new Map();
    const document = { activeElement: null, getElementById(id) {
        if (!elements.has(id)) elements.set(id, { textContent: '', className: '', value: '' });
        return elements.get(id);
    }};
    const monitor = create({ document, now: () => now, staleMs: 15000 });
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
});

test('disconnect, LWT offline and telemetry timeout clear actuator assumptions', () => {
    const { monitor, el, advance } = fixture();
    const sample = { temperature: 37.7, humidity: 61, heater: true, motor: true };
    monitor.telemetry(sample);
    monitor.disconnected();
    assert.equal(el('tele-temp').textContent, '--');
    assert.equal(el('tele-lamp-status').textContent, 'TIDAK DIKETAHUI');
    monitor.telemetry(sample);
    advance(15001);
    assert.match(el('inc-device-badge').textContent, /kedaluwarsa/);
    assert.equal(el('tele-motor-status').textContent, 'TIDAK DIKETAHUI');
    assert.equal(el('tele-motor-info').textContent, 'Menunggu jadwal dari perangkat');
    assert.equal(el('tele-lamp-mode').textContent, 'Belum terkonfirmasi');
    monitor.telemetry(sample);
    monitor.status({ online: false });
    assert.match(el('inc-device-badge').textContent, /Offline/);
});

test('retained telemetry and retained online status cannot impersonate a fresh sensor', () => {
    const { monitor, el } = fixture();
    monitor.status({ online: true }, true);
    monitor.telemetry({ temperature: 38, humidity: 60 }, true);
    assert.equal(el('tele-temp').textContent, '--');
    assert.doesNotMatch(el('inc-device-badge').textContent, /Online/);
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
