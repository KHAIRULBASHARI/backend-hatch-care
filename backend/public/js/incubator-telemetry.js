(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.IncubatorTelemetry = factory();
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    function create({ document, now = () => Date.now(), staleMs = 15000, storage = null, storageKey = 'incubator:last-mqtt:v2' }) {
        let receivedAt = null;
        let sensorAt = null;
        let unavailable = true;
        let unavailableMessage = 'Menunggu telemetri MQTT ESP Inkubator';
        let lastData = {};
        let countdown = null;
        let interval = null;
        let duration = null;
        const element = id => document.getElementById(id);
        const text = (id, value) => { if (element(id)) element(id).textContent = value; };
        const input = (id, value) => {
            const target = element(id);
            if (target && document.activeElement !== target) target.value = value;
        };
        const finite = value => value !== null && value !== '' && typeof value !== 'boolean' && Number.isFinite(Number(value));
        const boolean = value => value === true || value === 1 || value === 'true' || value === 'ON' || value === 'RUNNING';
        const badge = (message, online) => {
            text('inc-device-badge', message);
            if (element('inc-device-badge')) element('inc-device-badge').className = online ? 'badge-status badge-on' : 'badge-status badge-off';
        };
        const renderFreshness = () => {
            if (sensorAt === null) {
                text('telemetry-freshness', 'Belum ada pembacaan sensor MQTT');
                return;
            }
            const seconds = Math.max(0, Math.floor((now() - sensorAt) / 1000));
            const live = !unavailable && now() - sensorAt <= staleMs;
            const invalid = element('tele-temp')?.textContent === '--' || element('tele-hum')?.textContent === '--';
            const label = live ? (invalid ? 'Sensor DHT22 belum valid' : 'Data langsung MQTT') : 'Data terakhir';
            text('telemetry-freshness', `${label} · ${seconds} detik lalu`);
            if (element('telemetry-freshness')) element('telemetry-freshness').style?.setProperty('color', live ? 'var(--text-muted)' : 'var(--warning)');
        };
        const unknown = message => {
            unavailable = true;
            unavailableMessage = message;
            for (const id of ['tele-lamp-status', 'tele-motor-status']) {
                text(id, 'TIDAK DIKETAHUI');
                if (element(id)) element(id).className = 'badge-status badge-off';
            }
            if (receivedAt === null) {
                text('tele-setpoint-range', '--');
                text('tele-lamp-mode', 'Belum terkonfirmasi');
                text('tele-motor-info', 'Menunggu jadwal dari perangkat');
            } else {
                text('tele-lamp-mode', 'Belum terkonfirmasi');
                const schedule = interval !== null && duration !== null ? `Tiap ${interval} jam (${duration}s)` : 'Jadwal belum diterima';
                text('tele-motor-info', `${schedule} · Status terakhir; menunggu MQTT`);
            }
            badge(message, false);
            renderFreshness();
        };
        const renderSchedule = () => {
            if (interval === null || duration === null) return;
            let info = `Tiap ${interval} jam (${duration}s)`;
            if (countdown !== null && receivedAt !== null) {
                const seconds = Math.max(0, countdown - Math.floor((now() - receivedAt) / 1000));
                const minutes = Math.floor(seconds / 60);
                info += ` · Berikutnya ${minutes}m ${seconds % 60}s`;
            }
            text('tele-motor-info', info);
        };
        const telemetry = (data, retained = false, persist = true) => {
            if (!data || typeof data !== 'object' || Array.isArray(data) || retained) return;
            if (!['temperature', 'temp', 'humidity', 'hum', 'heater', 'lamp', 'motor'].some(key => Object.hasOwn(data, key))) return;
            receivedAt = now();
            unavailable = false;
            badge('ESP Inkubator: Online (MQTT)', true);
            const hasTemperature = Object.hasOwn(data, 'temperature') || Object.hasOwn(data, 'temp');
            const hasHumidity = Object.hasOwn(data, 'humidity') || Object.hasOwn(data, 'hum');
            if (hasTemperature || hasHumidity) sensorAt = receivedAt;
            const temperature = Object.hasOwn(data, 'temperature') ? data.temperature : data.temp;
            const humidity = Object.hasOwn(data, 'humidity') ? data.humidity : data.hum;
            if (hasTemperature) text('tele-temp', data.temperatureValid !== false && finite(temperature) ? Number(temperature).toFixed(1) : '--');
            if (hasHumidity) text('tele-hum', data.humidityValid !== false && finite(humidity) ? String(Math.round(Number(humidity))) : '--');
            if (Object.hasOwn(data, 'heater') || Object.hasOwn(data, 'lamp')) {
                const value = Object.hasOwn(data, 'heater') ? data.heater : data.lamp;
                const known = value !== null;
                const on = known && boolean(value);
                text('tele-lamp-status', known ? (on ? 'MENYALA (ON)' : 'MATI (OFF)') : 'TIDAK DIKETAHUI');
                if (element('tele-lamp-status')) element('tele-lamp-status').className = on ? 'badge-status badge-on' : 'badge-status badge-off';
            }
            if (Object.hasOwn(data, 'motor')) {
                const on = boolean(data.motor);
                text('tele-motor-status', data.motor === null ? 'TIDAK DIKETAHUI' : (on ? 'SEDANG BERPUTAR' : 'STANDBY'));
                if (element('tele-motor-status')) element('tele-motor-status').className = on ? 'badge-status badge-warn' : 'badge-status badge-off';
            }
            if (finite(data.tempMin) && finite(data.tempMax)) {
                text('tele-setpoint-range', `${Number(data.tempMin).toFixed(1)} - ${Number(data.tempMax).toFixed(1)}`);
                input('input-temp-min', data.tempMin);
                input('input-temp-max', data.tempMax);
            }
            if (['auto', 'on', 'off'].includes(data.lampMode)) {
                text('tele-lamp-mode', data.lampMode === 'auto' ? 'Auto (Termostat)' : (data.lampMode === 'on' ? 'Manual ON' : 'Manual OFF'));
                for (const mode of ['auto', 'on', 'off']) element(`btn-mode-${mode}`)?.classList?.toggle('active', mode === data.lampMode);
            }
            if (finite(data.motorIntervalHours)) { interval = Number(data.motorIntervalHours); input('input-motor-hours', interval); }
            if (finite(data.motorDurationSeconds)) { duration = Number(data.motorDurationSeconds); input('input-motor-duration', duration); }
            if (finite(data.captureIntervalSeconds)) input('capture-interval', Number(data.captureIntervalSeconds));
            countdown = finite(data.turnCountdownSec) ? Number(data.turnCountdownSec) : null;
            lastData = { ...lastData, ...data };
            renderSchedule();
            renderFreshness();
            try { if (persist) storage?.setItem(storageKey, JSON.stringify({ receivedAt, sensorAt, data: lastData })); } catch {}
        };
        const tick = () => {
            if (receivedAt !== null && now() - receivedAt > staleMs && !unavailable) unknown('ESP Inkubator: telemetri terlambat');
            else if (!unavailable) renderSchedule();
            else badge(unavailableMessage, false);
            renderFreshness();
        };
        const status = data => {
            if (data?.online === false) unknown('ESP Inkubator: Offline (MQTT)');
            else if (receivedAt === null) badge('MQTT terhubung; menunggu telemetri ESP', false);
        };
        text('tele-temp', '--');
        text('tele-hum', '--');
        unknown('Menunggu telemetri MQTT ESP Inkubator');
        try {
            const saved = JSON.parse(storage?.getItem(storageKey) ?? 'null');
            if (saved && finite(saved.receivedAt) && finite(saved.sensorAt) && saved.receivedAt <= now() && now() - saved.receivedAt < 86400000 && saved.data && typeof saved.data === 'object' && !Array.isArray(saved.data)) {
                telemetry(saved.data, false, false);
                receivedAt = Number(saved.receivedAt);
                sensorAt = Number(saved.sensorAt);
                unknown('Menampilkan data terakhir; menunggu MQTT ESP');
            }
        } catch {}
        return { telemetry, status, tick, disconnected: () => unknown('Koneksi MQTT terputus; menunggu sambungan ulang') };
    }
    return { create };
}));
