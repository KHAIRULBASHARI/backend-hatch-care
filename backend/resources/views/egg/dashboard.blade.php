<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Egg Candling & Capture Dashboard</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/mqtt/dist/mqtt.min.js"></script>

    <style>
        :root {
            --bg-base: #0c0f17;
            --bg-surface: #141923;
            --bg-card: #1b2232;
            --bg-input: #232c40;
            --border-color: #2b364e;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --primary: #f59e0b;
            --primary-hover: #d97706;
            --primary-glow: rgba(245, 158, 11, 0.25);
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --danger: #ef4444;
            --info: #38bdf8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 24px 16px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 28px;
        }

        .title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .title-group .icon {
            font-size: 28px;
            background: linear-gradient(135deg, #f59e0b, #ef4444);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }

        /* Status Badge */
        .device-status-badge {
            display: flex;
            align-items: center;
            gap: 16px;
            background-color: var(--bg-surface);
            padding: 8px 16px;
            border-radius: 9999px;
            border: 1px solid var(--border-color);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--text-muted);
            box-shadow: 0 0 8px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
        }

        .status-dot.online {
            background-color: var(--success);
            box-shadow: 0 0 10px var(--success-glow);
        }

        .status-dot.offline {
            background-color: var(--danger);
        }

        .device-telemetry {
            font-size: 12px;
            color: var(--text-secondary);
            border-left: 1px solid var(--border-color);
            padding-left: 12px;
            display: flex;
            gap: 12px;
        }

        /* Grid Layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        @media (min-width: 900px) {
            .main-grid {
                grid-template-columns: 360px 1fr;
            }
        }

        .card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Form Controls */
        .control-group {
            margin-bottom: 20px;
        }

        .control-label-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .control-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .control-value {
            font-weight: 700;
            color: var(--primary);
            background: var(--bg-card);
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 12px;
            font-family: monospace;
        }

        .slider {
            -webkit-appearance: none;
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: var(--bg-input);
            outline: none;
            transition: opacity .2s;
        }

        .slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 0 8px var(--primary-glow);
        }

        .slider::-moz-range-thumb {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 0 8px var(--primary-glow);
        }

        .slider-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Capture Button */
        .btn-capture {
            width: 100%;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #0c0f17;
            font-weight: 700;
            font-size: 15px;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 4px 15px var(--primary-glow);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-capture:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px var(--primary-glow);
        }

        .btn-capture:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Job Tracker */
        .tracker-card {
            margin-top: 20px;
            background-color: var(--bg-card);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 16px;
            display: none;
        }

        .tracker-status {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid var(--border-color);
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .tracker-time {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Preview Area */
        .preview-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 380px;
            background-color: var(--bg-card);
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }

        .preview-empty {
            text-align: center;
            color: var(--text-muted);
            padding: 40px;
        }

        .preview-img {
            max-width: 100%;
            max-height: 520px;
            object-fit: contain;
            display: none;
        }

        .preview-actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
            width: 100%;
            justify-content: flex-end;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-download {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-download:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* History Section */
        .history-section {
            margin-top: 36px;
        }

        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 18px;
            margin-top: 16px;
        }

        .history-item {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
            display: flex;
            flex-direction: column;
        }

        .history-item:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
        }

        .history-thumb {
            width: 100%;
            height: 140px;
            object-fit: cover;
            background-color: #000;
            cursor: pointer;
        }

        .history-meta {
            padding: 10px 12px;
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-surface);
        }

        .history-id {
            font-weight: 700;
            color: var(--text-primary);
        }

        .history-dl {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .history-dl:hover {
            text-decoration: underline;
        }

        /* Incubator Control & Monitoring Panel */
        .incubator-panel { margin-top: 32px; }
        .incubator-telemetry-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin: 18px 0 24px 0;
        }
        .telemetry-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .telemetry-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            font-weight: 600;
        }
        .telemetry-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .telemetry-sub {
            font-size: 12px;
            color: var(--text-muted);
        }
        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-on { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4); }
        .badge-off { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); }
        .badge-warn { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4); }

        .control-subsections {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 10px;
        }
        .control-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .control-box-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .control-box-desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .btn-group-mode {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            background: var(--bg-input);
            padding: 4px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .btn-mode {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            padding: 8px 10px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .btn-mode:hover { color: var(--text-primary); }
        .btn-mode.active {
            background: var(--primary);
            color: #111827;
            font-weight: 700;
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        .btn-motor-trigger {
            width: 100%;
            min-height: 48px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            border: 1px solid #38bdf8;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }
        .btn-motor-trigger:hover:not(:disabled) {
            background: linear-gradient(135deg, #0369a1, #075985);
            transform: translateY(-1px);
        }
        .btn-motor-trigger:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .field-group {
            display: grid;
            gap: 6px;
        }
        .field-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .field-group input {
            width: 100%;
            min-height: 40px;
            padding: 8px 12px;
            color: var(--text-primary);
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
        }
        .field-group input:focus {
            border-color: var(--primary);
            outline: none;
        }
        .btn-save-sm {
            min-height: 40px;
            background: var(--primary);
            color: #111827;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            padding: 8px 16px;
            transition: 0.2s;
        }
        .btn-save-sm:hover:not(:disabled) { background: var(--primary-hover); }
        .btn-save-sm:disabled { opacity: 0.6; cursor: wait; }
        .feedback-msg {
            font-size: 12px;
            color: var(--text-secondary);
            min-height: 1.4em;
        }

        .interval-settings { margin-top: 24px; }
        .interval-settings .slider-desc { color: var(--text-secondary); }
        .interval-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; margin-top: 14px; }
        .interval-field { display: grid; gap: 6px; flex: 1 1 220px; max-width: 360px; }
        .interval-field label { font-size: 13px; font-weight: 600; }
        .interval-field input { width: 100%; min-height: 44px; padding: 10px; color: var(--text-primary); background: var(--bg-input); border: 1px solid var(--text-secondary); border-radius: 8px; }
        .interval-form button { min-height: 44px; background: var(--primary); color: #111827; border: 0; }
        .interval-form button:disabled { opacity: .6; cursor: wait; }
        .interval-form :focus-visible { outline: 2px solid var(--primary); outline-offset: 3px; }
        .interval-message { min-height: 1.4em; margin-top: 10px; color: var(--text-secondary); font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="title-group">
                <span class="icon">🥚</span>
                <div>
                    <h1>Egg Candling & Capture</h1>
                    <div class="subtitle">Sistem Pemantauan dan Pengambilan Foto Telur Otomatis</div>
                </div>
            </div>

            <div class="device-status-badge">
                <div class="status-indicator">
                    <span id="status-dot" class="status-dot"></span>
                    <span id="status-text">Memeriksa ESP...</span>
                </div>
                <div class="device-telemetry">
                    <span>RSSI: <strong id="telemetry-rssi">-</strong></span>
                    <span>Last: <strong id="telemetry-last">-</strong></span>
                </div>
            </div>
        </header>

        <main class="main-grid">
            <!-- Left Panel: Controls -->
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">⚙️ Konfigurasi Kamera</h2>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Exposure (Pencahayaan)</span>
                        <span id="val-exposure" class="control-value">500</span>
                    </div>
                    <input id="slider-exposure" type="range" min="0" max="1200" value="500" class="slider">
                    <div class="slider-desc">Rentang: 0 - 1200 (Default: 500)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Sensor Gain</span>
                        <span id="val-gain" class="control-value">0</span>
                    </div>
                    <input id="slider-gain" type="range" min="0" max="30" value="0" class="slider">
                    <div class="slider-desc">Rentang: 0 - 30 (Default: 0 - Disarankan 0 agar bebas noise)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">Kualitas JPEG</span>
                        <span id="val-quality" class="control-value">4</span>
                    </div>
                    <input id="slider-quality" type="range" min="4" max="15" value="4" class="slider">
                    <div class="slider-desc">Rentang: 4 (Paling Tajam & Jernih) - 15 (Rendah)</div>
                </div>

                <div class="control-group">
                    <div class="control-label-row">
                        <span class="control-label">LED Settle Delay (ms)</span>
                        <span id="val-settle" class="control-value">350</span>
                    </div>
                    <input id="slider-settle" type="range" min="100" max="3000" step="50" value="350" class="slider">
                    <div class="slider-desc">Waktu tunggu LED sebelum jepret (Default: 350 ms agar sensor stabil)</div>
                </div>

                <button id="btn-capture" class="btn-capture">
                    <span>📷 Ambil Foto</span>
                </button>

                <form id="candling-upload-form" style="margin-top: 24px; display: grid; gap: 10px;">
                    <label for="candling-image" class="control-label">Atau unggah foto candling (JPEG/PNG, maks. 5 MB)</label>
                    <input id="candling-image" name="image" type="file" accept="image/jpeg,image/png" required>
                    <button id="candling-upload-button" class="btn-save-sm" type="submit">Unggah & Analisis</button>
                    <div id="candling-upload-message" class="feedback-msg" role="status" aria-live="polite"></div>
                </form>

                <div id="tracker-box" class="tracker-card">
                    <div class="tracker-status">
                        <div id="tracker-spinner" class="spinner"></div>
                        <span id="tracker-msg">Memulai...</span>
                    </div>
                    <div id="tracker-time" class="tracker-time">Waktu berlalu: 0s</div>
                </div>
            </section>

            <!-- Right Panel: Preview & AI Analysis -->
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">🖼️ Hasil Tangkapan & Analisis AI</h2>
                </div>

                <div class="preview-container">
                    <div id="preview-placeholder" class="preview-empty">
                        <svg style="width: 48px; height: 48px; margin-bottom: 12px; opacity: 0.4;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <p>Belum ada foto yang diambil sesi ini.<br>Klik tombol <strong>Ambil Foto</strong> untuk memulai.</p>
                    </div>
                    <img id="preview-image" class="preview-img" alt="Captured Egg">
                </div>

                <!-- AI NN Prediction Result Box -->
                <div id="ai-result-box" style="display: none; margin-top: 20px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                        <span style="font-size: 13px; color: var(--text-secondary); font-weight: 600;">HASIL KLASIFIKASI MODEL NN</span>
                        <span id="ai-badge" style="padding: 4px 14px; border-radius: 9999px; font-weight: 700; font-size: 13px; text-transform: uppercase;">-</span>
                    </div>

                    <!-- Probabilities -->
                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                            <span>Tingkat Keyakinan (Confidence)</span>
                            <strong id="ai-confidence" style="color: var(--primary);">-</strong>
                        </div>
                        <div style="width: 100%; height: 8px; background: var(--bg-input); border-radius: 4px; overflow: hidden;">
                            <div id="ai-confidence-bar" style="height: 100%; width: 0%; background: var(--primary); transition: width 0.5s ease;"></div>
                        </div>
                    </div>

                    <!-- Probability Bars Breakdown -->
                    <div id="ai-breakdown" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; font-size: 11px; text-align: center; margin-bottom: 16px;">
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #2ca02c; font-weight: 600;">Fertil</div>
                            <div id="prob-fertil">-</div>
                        </div>
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #1f77b4; font-weight: 600;">Infertil</div>
                            <div id="prob-infertil">-</div>
                        </div>
                        <div style="background: var(--bg-surface); padding: 6px; border-radius: 6px;">
                            <div style="color: #d62728; font-weight: 600;">Mortal</div>
                            <div id="prob-mortal">-</div>
                        </div>
                    </div>

                    <!-- LLM Expert Analysis Box -->
                    <div id="llm-box" style="display: none; background: rgba(56, 189, 248, 0.08); border-left: 3px solid var(--info); padding: 14px 16px; border-radius: 0 8px 8px 0; font-size: 13px; line-height: 1.6; color: var(--text-primary); margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 700; color: var(--info); margin-bottom: 8px;">
                            <span>🧠 Analisis Pakar AI (LLM Vision)</span>
                        </div>
                        <div id="llm-content" style="white-space: pre-wrap; font-family: inherit;"></div>
                    </div>

                    <!-- Visualizations Gallery (5 Tahapan Pipeline Computer Vision) -->
                    <div id="ai-vis-container" style="display: none;">
                        <span style="font-size: 12px; color: var(--text-secondary); font-weight: 600; display: block; margin-bottom: 8px;">5 TAHAP PEMROSESAN COMPUTER VISION (KLIK UNTUK LIHAT):</span>
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 8px;">
                            <div style="text-align: center;">
                                <img id="vis-crop" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 1: Crop & Kontras" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">1. Crop Telur</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-roi" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 2: Binerisasi ROI" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">2. ROI Biner</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-masked" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 3: Masked RGB" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">3. Masked RGB</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-peta" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 4: Peta Darah" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">4. Peta Darah</span>
                            </div>
                            <div style="text-align: center;">
                                <img id="vis-jaringan" style="width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); cursor: pointer;" title="Tahap 5: Jaringan Vaskular" onclick="showInPreview(this.src)">
                                <span style="font-size: 10px; color: var(--text-muted); display: block; margin-top: 4px;">5. Vaskular</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="candling-metrics" class="feedback-msg" style="margin-top: 16px;" role="status"></div>
                <div class="preview-actions">
                    <a id="btn-download" class="btn-action btn-download" style="display: none;" download="egg-candling.jpg">
                        ⬇️ Unduh Foto
                    </a>
                </div>
            </section>
        </main>

        <!-- Section: Panel Kontrol & Pemantauan Inkubator (Suhu & Dinamo Goyang) -->
        <section class="card incubator-panel" id="incubator-section" aria-labelledby="incubator-title">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                <div class="card-header" style="margin-bottom: 0;">
                    <h2 id="incubator-title" class="card-title">🎛️ Kontrol & Monitoring Inkubator (Suhu & Dinamo)</h2>
                    <p class="slider-desc" style="margin-top: 4px;">Kendali manual suhu & lampu pemanas, pembalik telur (dinamo goyang), dan telemetri sensor DHT22.</p>
                </div>
                <div>
                    <span id="inc-device-badge" class="badge-status badge-off">ESP Inkubator: Offline</span>
                </div>
            </div>

            <!-- Live Telemetry Grid -->
            <div class="incubator-telemetry-grid">
                <div class="telemetry-card">
                    <span class="telemetry-label">Suhu Lingkungan</span>
                    <div class="telemetry-value"><span id="tele-temp">--</span>°C</div>
                    <span class="telemetry-sub" id="tele-temp-sub">Setpoint: <span id="tele-setpoint-range">37.0 - 38.0</span>°C</span>
                </div>
                <div class="telemetry-card">
                    <span class="telemetry-label">Kelembaban Udara</span>
                    <div class="telemetry-value"><span id="tele-hum">--</span>%</div>
                    <span class="telemetry-sub">Sensor DHT22 Real-time</span>
                </div>
                <div class="telemetry-card">
                    <span class="telemetry-label">Lampu Pemanas</span>
                    <div style="margin-top: 4px;">
                        <span id="tele-lamp-status" class="badge-status badge-off">MATI (OFF)</span>
                    </div>
                    <span class="telemetry-sub" style="margin-top: 6px;">Mode: <strong id="tele-lamp-mode">Auto</strong></span>
                </div>
                <div class="telemetry-card">
                    <span class="telemetry-label">Dinamo Pembalik Telur</span>
                    <div style="margin-top: 4px;">
                        <span id="tele-motor-status" class="badge-status badge-off">STANDBY</span>
                    </div>
                    <span class="telemetry-sub" style="margin-top: 6px;" id="tele-motor-info">Tiap 4 jam (15s)</span>
                </div>
            </div>

            <!-- Control Panels Subsections -->
            <div class="control-subsections">
                <!-- 1. Kontrol Suhu & Pemanas -->
                <div class="control-box">
                    <div class="control-box-title">
                        <span>🔥 Kontrol Suhu & Mode Lampu</span>
                    </div>
                    <p class="control-box-desc">Atur mode pemanas secara manual atau otomatis, serta batas histeresis termostat lampu.</p>
                    
                    <div style="display: grid; gap: 8px;">
                        <label style="font-size: 12px; font-weight: 600; color: var(--text-secondary);">Mode Lampu Pemanas</label>
                        <div class="btn-group-mode" role="group" aria-label="Mode Lampu">
                            <button type="button" class="btn-mode active" id="btn-mode-auto" onclick="setLampMode('auto')">Auto (Termostat)</button>
                            <button type="button" class="btn-mode" id="btn-mode-on" onclick="setLampMode('on')">Manual ON</button>
                            <button type="button" class="btn-mode" id="btn-mode-off" onclick="setLampMode('off')">Manual OFF</button>
                        </div>
                    </div>

                    <form id="temp-form" style="display: grid; gap: 12px; margin-top: 6px;">
                        <div class="form-row">
                            <div class="field-group">
                                <label for="input-temp-min">Suhu Min Lampu ON (°C)</label>
                                <input id="input-temp-min" type="number" step="0.1" min="30" max="45" value="37.0" required>
                            </div>
                            <div class="field-group">
                                <label for="input-temp-max">Suhu Max Lampu OFF (°C)</label>
                                <input id="input-temp-max" type="number" step="0.1" min="30" max="45" value="38.0" required>
                            </div>
                        </div>
                        <button id="btn-save-temp" class="btn-save-sm" type="submit">Simpan Setpoint Suhu</button>
                        <div id="msg-temp" class="feedback-msg"></div>
                    </form>
                </div>

                <!-- 2. Kontrol Dinamo Goyang -->
                <div class="control-box">
                    <div class="control-box-title">
                        <span>🔄 Dinamo Goyang (Pembalik Telur)</span>
                    </div>
                    <p class="control-box-desc">Picu pembalikan telur manual sekarang juga atau sesuaikan jadwal putar dinamo otomatis.</p>

                    <!-- Tombol Manual Goyang Sekarang -->
                    <div>
                        <button type="button" id="btn-trigger-motor" class="btn-motor-trigger" onclick="triggerMotorManual()">
                            <span id="motor-btn-icon">⚡</span>
                            <span id="motor-btn-text">Putar / Goyang Dinamo Sekarang</span>
                        </button>
                        <div id="msg-motor-trigger" class="feedback-msg" style="margin-top: 8px;"></div>
                    </div>

                    <form id="motor-form" style="display: grid; gap: 12px; border-top: 1px solid var(--border-color); padding-top: 14px;">
                        <div class="form-row">
                            <div class="field-group">
                                <label for="input-motor-hours">Interval Putar (Jam)</label>
                                <input id="input-motor-hours" type="number" min="1" max="24" step="1" value="4" required>
                            </div>
                            <div class="field-group">
                                <label for="input-motor-duration">Durasi Putar (Detik)</label>
                                <input id="input-motor-duration" type="number" min="1" max="180" step="1" value="15" required>
                            </div>
                        </div>
                        <button id="btn-save-motor" class="btn-save-sm" type="submit">Simpan Jadwal Dinamo</button>
                        <div id="msg-motor-schedule" class="feedback-msg"></div>
                    </form>
                </div>

                <!-- 3. Kamera & Jeda Foto + Lampu Otomatis Inkubator -->
                <div class="control-box">
                    <div class="control-box-title">
                        <span>📷 Interval Foto & Nyala Lampu/LED</span>
                    </div>
                    <p class="control-box-desc">Atur jeda waktu ESP32-CAM menyalakan lampu warm/LED dan memotret rak telur otomatis ke web (disimpan 1 jam terakhir).</p>

                    <form id="interval-form" style="display: grid; gap: 12px; margin-top: auto;">
                        <div class="field-group">
                            <label for="capture-interval">Interval Foto & Lampu (Detik)</label>
                            <input id="capture-interval" type="number" min="15" max="3600" step="1" value="120" required>
                            <span class="control-box-desc">Nilai antara 15 sampai 3600 detik (default: 120 detik / 2 menit).</span>
                        </div>
                        <button id="interval-save" class="btn-save-sm" type="submit">Simpan Interval</button>
                        <div id="interval-message" class="feedback-msg"></div>
                    </form>
                </div>
            </div>

            <!-- Live Preview & Galeri Foto Kamera Inkubator (1 Jam Terakhir) -->
            <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 14px;">
                    <div>
                        <h3 class="card-title">📸 Foto Kamera Inkubator & Riwayat 1 Jam Terakhir</h3>
                        <p class="slider-desc" style="margin-top: 4px;">Foto otomatis dari ESP32-CAM inkubator disimpan selama 1 jam terakhir (otomatis terhapus setelah &gt;1 jam).</p>
                    </div>
                    <span id="inc-photo-count" class="badge-status badge-off">0 foto (1 jam terakhir)</span>
                </div>

                <!-- Preview Foto Inkubator yang Dipilih / Terbaru -->
                <div class="preview-container" style="min-height: 360px; margin-bottom: 16px;">
                    <div id="inc-preview-empty" class="preview-empty">
                        <p>Belum ada foto inkubator dalam 1 jam terakhir.<br>ESP32-CAM akan mengunggah foto otomatis sesuai jeda yang diatur.</p>
                    </div>
                    <img id="inc-preview-img" class="preview-img" style="max-height: 760px; width: 100%; cursor: zoom-in;" title="Klik untuk buka ukuran penuh (100%)" onclick="if(this.src)window.open(this.src,'_blank')" alt="Foto Kamera Inkubator">
                </div>
                <div id="inc-preview-meta" style="display: none; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; font-size: 13px; color: var(--text-secondary);">
                    <div id="inc-preview-info">-</div>
                    <a id="inc-preview-download" class="btn-action btn-download" href="#" download="incubator.jpg">⬇️ Unduh Foto Ini</a>
                </div>

                <!-- Grid Galeri 1 Jam Terakhir -->
                <div id="inc-history-grid" class="history-grid">
                    <div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Belum ada foto inkubator dalam 1 jam terakhir.</div>
                </div>
            </div>
        </section>

        <!-- History Section -->
        <section class="history-section">
            <h2 class="card-title">🕒 Riwayat Tangkapan Terakhir (Maks 20)</h2>
            <div id="history-grid" class="history-grid">
                <div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Memuat riwayat...</div>
            </div>
        </section>
    </div>

    <script>
        // ─── Kontrol & Telemetri Inkubator (Suhu & Dinamo Goyang) ────────────────────
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        const inputTempMin = document.getElementById('input-temp-min');
        const inputTempMax = document.getElementById('input-temp-max');
        const btnSaveTemp = document.getElementById('btn-save-temp');
        const msgTemp = document.getElementById('msg-temp');

        const inputMotorHours = document.getElementById('input-motor-hours');
        const inputMotorDuration = document.getElementById('input-motor-duration');
        const btnSaveMotor = document.getElementById('btn-save-motor');
        const btnTriggerMotor = document.getElementById('btn-trigger-motor');
        const motorBtnText = document.getElementById('motor-btn-text');
        const msgMotorTrigger = document.getElementById('msg-motor-trigger');
        const msgMotorSchedule = document.getElementById('msg-motor-schedule');

        const intervalInput = document.getElementById('capture-interval');
        const intervalButton = document.getElementById('interval-save');
        const intervalMessage = document.getElementById('interval-message');

        function updateModeButtons(mode) {
            ['auto', 'on', 'off'].forEach(m => {
                const btn = document.getElementById(`btn-mode-${m}`);
                if (btn) {
                    if (m === mode) btn.classList.add('active');
                    else btn.classList.remove('active');
                }
            });
        }

        async function setLampMode(mode) {
            updateModeButtons(mode);
            msgTemp.style.color = 'var(--text-secondary)';
            msgTemp.textContent = `Mengubah mode lampu ke ${mode.toUpperCase()}...`;
            try {
                const res = await fetch('/incubator/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ lampMode: mode })
                });
                if (!res.ok) throw new Error();
                document.getElementById('tele-lamp-mode').textContent = mode === 'auto' ? 'Auto (Termostat)' : (mode === 'on' ? 'Manual ON' : 'Manual OFF');
                msgTemp.style.color = 'var(--success)';
                msgTemp.textContent = `Mode lampu berhasil diubah ke: ${mode.toUpperCase()}`;
            } catch (e) {
                msgTemp.style.color = 'var(--danger)';
                msgTemp.textContent = 'Gagal mengubah mode lampu. Periksa koneksi.';
            }
        }

        document.getElementById('temp-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const min = parseFloat(inputTempMin.value);
            const max = parseFloat(inputTempMax.value);
            if (min >= max) {
                msgTemp.style.color = 'var(--danger)';
                msgTemp.textContent = 'Suhu Min harus lebih kecil dari Suhu Max!';
                return;
            }
            btnSaveTemp.disabled = true;
            msgTemp.style.color = 'var(--text-secondary)';
            msgTemp.textContent = 'Menyimpan setpoint suhu ke cloud...';
            try {
                const res = await fetch('/incubator/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ tempMin: min, tempMax: max })
                });
                if (!res.ok) throw new Error();
                document.getElementById('tele-setpoint-range').textContent = `${min.toFixed(1)} - ${max.toFixed(1)}`;
                msgTemp.style.color = 'var(--success)';
                msgTemp.textContent = 'Setpoint suhu berhasil disimpan! ESP32 akan menerapkannya.';
            } catch (err) {
                msgTemp.style.color = 'var(--danger)';
                msgTemp.textContent = 'Gagal menyimpan setpoint suhu.';
            } finally {
                btnSaveTemp.disabled = false;
            }
        });

        async function triggerMotorManual() {
            if (!confirm('Putar / goyang dinamo pembalik telur sekarang?')) return;
            btnTriggerMotor.disabled = true;
            motorBtnText.textContent = 'Mengirim Perintah Dinamo...';
            msgMotorTrigger.style.color = 'var(--info)';
            msgMotorTrigger.textContent = 'Mengirim instruksi putar dinamo ke cloud...';

            try {
                const res = await fetch('/incubator/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ triggerMotor: true })
                });
                if (!res.ok) throw new Error();
                msgMotorTrigger.style.color = 'var(--success)';
                msgMotorTrigger.textContent = '✅ Perintah berhasil dikirim! ESP32 akan langsung memutar dinamo pembalik telur.';
                motorBtnText.textContent = 'Perintah Terkirim (Menunggu Putaran)';
                setTimeout(() => {
                    btnTriggerMotor.disabled = false;
                    motorBtnText.textContent = 'Putar / Goyang Dinamo Sekarang';
                }, 6000);
            } catch (e) {
                msgMotorTrigger.style.color = 'var(--danger)';
                msgMotorTrigger.textContent = 'Gagal mengirim perintah putar dinamo.';
                btnTriggerMotor.disabled = false;
                motorBtnText.textContent = 'Putar / Goyang Dinamo Sekarang';
            }
        }

        document.getElementById('motor-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            btnSaveMotor.disabled = true;
            msgMotorSchedule.style.color = 'var(--text-secondary)';
            msgMotorSchedule.textContent = 'Menyimpan jadwal dinamo...';
            try {
                const hours = parseInt(inputMotorHours.value);
                const duration = parseInt(inputMotorDuration.value);
                const res = await fetch('/incubator/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ motorIntervalHours: hours, motorDurationSeconds: duration })
                });
                if (!res.ok) throw new Error();
                document.getElementById('tele-motor-info').textContent = `Tiap ${hours} jam (${duration}s)`;
                msgMotorSchedule.style.color = 'var(--success)';
                msgMotorSchedule.textContent = 'Jadwal putaran dinamo berhasil disimpan!';
            } catch (e) {
                msgMotorSchedule.style.color = 'var(--danger)';
                msgMotorSchedule.textContent = 'Gagal menyimpan jadwal dinamo.';
            } finally {
                btnSaveMotor.disabled = false;
            }
        });

        document.getElementById('interval-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            intervalButton.disabled = true;
            intervalMessage.style.color = 'var(--text-secondary)';
            intervalMessage.textContent = 'Menyimpan jeda foto...';
            try {
                const sec = parseInt(intervalInput.value);
                const res = await fetch('/incubator/control', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ captureIntervalSeconds: sec })
                });
                if (!res.ok) throw new Error();
                intervalMessage.style.color = 'var(--success)';
                intervalMessage.textContent = 'Jeda unggah foto tersimpan!';
            } catch (e) {
                intervalMessage.style.color = 'var(--danger)';
                intervalMessage.textContent = 'Gagal menyimpan jeda foto.';
            } finally {
                intervalButton.disabled = false;
            }
        });

        function applyLiveTelemetry(data) {
            if (!data) return;
            const teleTemp = document.getElementById('tele-temp');
            const teleHum = document.getElementById('tele-hum');
            const t = data.temperature !== undefined && data.temperature !== null ? data.temperature : data.temp;
            const h = data.humidity !== undefined && data.humidity !== null ? data.humidity : data.hum;
            if (teleTemp && t !== null && t !== undefined) {
                teleTemp.textContent = parseFloat(t).toFixed(1);
            }
            if (teleHum && h !== null && h !== undefined) {
                teleHum.textContent = Math.round(h);
            }

            if (data.heater !== undefined || data.lamp !== undefined) {
                const isHeater = !!(data.heater ?? data.lamp);
                const teleLamp = document.getElementById('tele-lamp-status');
                if (teleLamp) {
                    teleLamp.className = isHeater ? 'badge-status badge-on' : 'badge-status badge-off';
                    teleLamp.textContent = isHeater ? 'MENYALA (ON)' : 'MATI (OFF)';
                }
            }

            if (data.motor !== undefined) {
                const isMotor = !!data.motor;
                const teleMotor = document.getElementById('tele-motor-status');
                if (teleMotor) {
                    teleMotor.className = isMotor ? 'badge-status badge-warn' : 'badge-status badge-off';
                    teleMotor.textContent = isMotor ? 'SEDANG BERPUTAR' : 'STANDBY';
                }
            }

            const incBadge = document.getElementById('inc-device-badge');
            if (incBadge) {
                incBadge.className = 'badge-status badge-on';
                incBadge.textContent = 'ESP Inkubator: Online (MQTT ⚡)';
            }

            if (data.tempMin !== undefined && data.tempMax !== undefined) {
                const range = document.getElementById('tele-setpoint-range');
                if (range) range.textContent = `${parseFloat(data.tempMin).toFixed(1)} - ${parseFloat(data.tempMax).toFixed(1)}`;
                if (typeof inputTempMin !== 'undefined' && document.activeElement !== inputTempMin) inputTempMin.value = data.tempMin;
                if (typeof inputTempMax !== 'undefined' && document.activeElement !== inputTempMax) inputTempMax.value = data.tempMax;
            }
            if (data.lampMode) {
                updateModeButtons(data.lampMode);
                const modeEl = document.getElementById('tele-lamp-mode');
                if (modeEl) modeEl.textContent = data.lampMode === 'auto' ? 'Auto (Termostat)' : (data.lampMode === 'on' ? 'Manual ON' : 'Manual OFF');
            }
        }

        async function pollIncubatorTelemetry() {
            try {
                const res = await fetch('/incubator/latest');
                if (!res.ok) return;
                const data = await res.json();

                // Telemetri Suhu & Kelembaban
                const teleTemp = document.getElementById('tele-temp');
                const teleHum = document.getElementById('tele-hum');
                if (data.temperature !== null && data.temperature !== undefined) {
                    teleTemp.textContent = parseFloat(data.temperature).toFixed(1);
                }
                if (data.humidity !== null && data.humidity !== undefined) {
                    teleHum.textContent = Math.round(data.humidity);
                }

                // Telemetri Lampu
                const teleLamp = document.getElementById('tele-lamp-status');
                if (data.lamp) {
                    teleLamp.className = 'badge-status badge-on';
                    teleLamp.textContent = 'MENYALA (ON)';
                } else {
                    teleLamp.className = 'badge-status badge-off';
                    teleLamp.textContent = 'MATI (OFF)';
                }

                // Telemetri Dinamo
                const teleMotor = document.getElementById('tele-motor-status');
                if (data.motor) {
                    teleMotor.className = 'badge-status badge-warn';
                    teleMotor.textContent = 'SEDANG BERPUTAR';
                } else {
                    teleMotor.className = 'badge-status badge-off';
                    teleMotor.textContent = 'STANDBY';
                }

                // Status Koneksi ESP Inkubator
                const incBadge = document.getElementById('inc-device-badge');
                if (data.online) {
                    incBadge.className = 'badge-status badge-on';
                    incBadge.textContent = 'ESP Inkubator: Online';
                } else {
                    incBadge.className = 'badge-status badge-off';
                    incBadge.textContent = data.last_seen_sec !== null ? `ESP Offline (${data.last_seen_sec}s lalu)` : 'ESP Inkubator: Offline';
                }

                // Update Control Values jika ada & user tidak sedang mengetik
                if (data.control) {
                    const c = data.control;
                    if (c.lampMode) {
                        updateModeButtons(c.lampMode);
                        document.getElementById('tele-lamp-mode').textContent = c.lampMode === 'auto' ? 'Auto (Termostat)' : (c.lampMode === 'on' ? 'Manual ON' : 'Manual OFF');
                    }
                    if (c.tempMin !== undefined && c.tempMax !== undefined) {
                        document.getElementById('tele-setpoint-range').textContent = `${parseFloat(c.tempMin).toFixed(1)} - ${parseFloat(c.tempMax).toFixed(1)}`;
                        if (document.activeElement !== inputTempMin) inputTempMin.value = c.tempMin;
                        if (document.activeElement !== inputTempMax) inputTempMax.value = c.tempMax;
                    }
                    if (c.motorIntervalHours !== undefined && c.motorDurationSeconds !== undefined) {
                        document.getElementById('tele-motor-info').textContent = `Tiap ${c.motorIntervalHours} jam (${c.motorDurationSeconds}s)`;
                        if (document.activeElement !== inputMotorHours) inputMotorHours.value = c.motorIntervalHours;
                        if (document.activeElement !== inputMotorDuration) inputMotorDuration.value = c.motorDurationSeconds;
                    }
                    if (c.captureIntervalSeconds !== undefined && document.activeElement !== intervalInput) {
                        intervalInput.value = c.captureIntervalSeconds;
                    }
                }

                // Render Foto Terbaru & Galeri Riwayat 1 Jam Terakhir
                const history = Array.isArray(data.history) ? data.history : [];
                const countBadge = document.getElementById('inc-photo-count');
                countBadge.textContent = `${history.length} foto (1 jam terakhir)`;
                countBadge.className = history.length > 0 ? 'badge-status badge-on' : 'badge-status badge-off';

                const incGrid = document.getElementById('inc-history-grid');
                const historySignature = history.map(h => h.id).join(',');
                if (history.length === 0) {
                    if (window._lastIncSig !== '') {
                        window._lastIncSig = '';
                        incGrid.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Belum ada foto inkubator dalam 1 jam terakhir.</div>';
                    }
                } else if (window._lastIncSig !== historySignature) {
                    const wasViewingLatest = !window._selectedIncId || window._selectedIncId === (window._lastLatestIncId || '');
                    window._lastIncSig = historySignature;
                    window._lastLatestIncId = history[0].id;
                    window._incHistoryData = history;

                    if (wasViewingLatest || !history.some(h => h.id === window._selectedIncId)) {
                        selectIncubatorPhoto(history[0].id);
                    }

                    incGrid.innerHTML = history.map(item => {
                        const timeStr = new Date(item.at).toLocaleTimeString('id-ID');
                        const tempStr = item.temperature !== null && item.temperature !== undefined ? `${parseFloat(item.temperature).toFixed(1)}°C` : '--°C';
                        const humStr = item.humidity !== null && item.humidity !== undefined ? `${Math.round(item.humidity)}%` : '--%';
                        return `
                            <div class="history-item">
                                <img src="${item.url}" class="history-thumb" alt="Foto Inkubator ${timeStr}" onclick="selectIncubatorPhoto('${item.id}')">
                                <div class="history-meta">
                                    <div>
                                        <span class="history-id">${timeStr}</span>
                                        <div style="color: var(--text-muted);">${tempStr} · ${humStr} · ${item.size_kb || 0} KB</div>
                                    </div>
                                    <a href="${item.url}" download="${item.filename || 'incubator.jpg'}" class="history-dl">Unduh</a>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            } catch (e) {
                // Silently handle transient network issues
            }
        }

        function selectIncubatorPhoto(id) {
            const list = window._incHistoryData || [];
            const item = list.find(h => h.id === id) || list[0];
            if (!item) return;
            window._selectedIncId = item.id;

            const img = document.getElementById('inc-preview-img');
            const empty = document.getElementById('inc-preview-empty');
            const meta = document.getElementById('inc-preview-meta');
            const info = document.getElementById('inc-preview-info');
            const dl = document.getElementById('inc-preview-download');

            img.src = item.url;
            img.style.display = 'block';
            empty.style.display = 'none';
            meta.style.display = 'flex';
            dl.href = item.url;
            dl.download = item.filename || 'incubator.jpg';

            const timeStr = new Date(item.at).toLocaleTimeString('id-ID');
            const tempStr = item.temperature !== null && item.temperature !== undefined ? `${parseFloat(item.temperature).toFixed(1)}°C` : '--°C';
            const humStr = item.humidity !== null && item.humidity !== undefined ? `${Math.round(item.humidity)}%` : '--%';
            const baseInfo = `<strong>Waktu:</strong> ${timeStr} &nbsp;|&nbsp; <strong>Suhu:</strong> ${tempStr} &nbsp;|&nbsp; <strong>Kelembaban:</strong> ${humStr} &nbsp;|&nbsp; <strong>Ukuran:</strong> ${item.size_kb || 0} KB`;
            info.innerHTML = baseInfo;
            img.onload = () => {
                info.innerHTML = `${baseInfo} &nbsp;|&nbsp; <strong>Resolusi:</strong> ${img.naturalWidth}×${img.naturalHeight} px`;
            };
        }

        pollIncubatorTelemetry();
        setInterval(pollIncubatorTelemetry, 3000);

        // MQTT WebSocket Live Real-Time Client (Cloudflare WSS Port 443)
        let dashMqtt = null;
        function initDashboardMqtt() {
            if (typeof mqtt === 'undefined') return;
            try {
                dashMqtt = mqtt.connect('wss://mqtt.walker880.my.id:443/mqtt', {
                    clientId: 'web_' + Math.random().toString(16).slice(2, 10),
                    clean: true,
                    connectTimeout: 5000,
                    reconnectPeriod: 4000
                });

                dashMqtt.on('connect', () => {
                    console.log('[MQTT-WSS] Terhubung ke broker MQTT cloud (Port 443)!');
                    dashMqtt.subscribe('incubator/+/telemetry');
                    dashMqtt.subscribe('incubator/telemetry');
                    dashMqtt.subscribe('incubator/+/temperature');
                    dashMqtt.subscribe('incubator/+/humidity');
                    dashMqtt.subscribe('incubator/+/heater');
                    dashMqtt.subscribe('incubator/+/motor');
                    dashMqtt.subscribe('candling/+/telemetry');
                    dashMqtt.subscribe('candling/+/status');
                });

                dashMqtt.on('message', (topic, payload) => {
                    const text = payload.toString();
                    if (topic.startsWith('candling/')) {
                        const dot = document.getElementById('status-dot');
                        const textEl = document.getElementById('status-text');
                        const rssi = document.getElementById('telemetry-rssi');
                        const last = document.getElementById('telemetry-last');
                        try {
                            const data = JSON.parse(text);
                            if (data.online === true || data.job) {
                                if (dot) dot.className = 'status-dot online';
                                if (textEl) {
                                    textEl.textContent = data.status === 'capturing' ? 'ESP Candling: Memotret...' : 'ESP Online (MQTT ⚡)';
                                    textEl.style.color = 'var(--success)';
                                }
                                if (data.rssi !== undefined && rssi) rssi.textContent = `${data.rssi} dBm`;
                                if (last) last.textContent = 'Baru saja';
                            } else if (data.online === false) {
                                if (dot) dot.className = 'status-dot offline';
                                if (textEl) {
                                    textEl.textContent = 'ESP Offline';
                                    textEl.style.color = 'var(--danger)';
                                }
                            }
                        } catch (e) {}
                        return;
                    }

                    if (topic.endsWith('/telemetry') || topic === 'incubator/telemetry') {
                        try {
                            const data = JSON.parse(text);
                            applyLiveTelemetry(data);
                        } catch (e) {}
                    } else if (topic.endsWith('/temperature') || topic === 'incubator/temperature') {
                        const el = document.getElementById('tele-temp');
                        if (el && text.length > 0) el.textContent = parseFloat(text).toFixed(1);
                    } else if (topic.endsWith('/humidity') || topic === 'incubator/humidity') {
                        const el = document.getElementById('tele-hum');
                        if (el && text.length > 0) el.textContent = Math.round(parseFloat(text));
                    } else if (topic.endsWith('/heater')) {
                        const el = document.getElementById('tele-lamp-status');
                        if (el) {
                            const on = text === 'ON';
                            el.className = on ? 'badge-status badge-on' : 'badge-status badge-off';
                            el.textContent = on ? 'MENYALA (ON)' : 'MATI (OFF)';
                        }
                    } else if (topic.endsWith('/motor')) {
                        const el = document.getElementById('tele-motor-status');
                        if (el) {
                            const run = text === 'RUNNING';
                            el.className = run ? 'badge-status badge-warn' : 'badge-status badge-off';
                            el.textContent = run ? 'SEDANG BERPUTAR' : 'STANDBY';
                        }
                    }
                });
            } catch (err) {
                console.warn('[MQTT-WSS] Inisialisasi WebSocket MQTT dibatalkan:', err);
            }
        }
        initDashboardMqtt();

        // Sliders binding
        const sliders = [
            { slider: 'slider-exposure', display: 'val-exposure' },
            { slider: 'slider-gain', display: 'val-gain' },
            { slider: 'slider-quality', display: 'val-quality' },
            { slider: 'slider-settle', display: 'val-settle' }
        ];

        sliders.forEach(item => {
            const input = document.getElementById(item.slider);
            const valDisplay = document.getElementById(item.display);
            input.addEventListener('input', () => {
                valDisplay.textContent = input.value;
            });
        });

        // Device Status Polling (every 5 seconds)
        async function checkStatus() {
            try {
                const res = await fetch('/egg/status');
                if (!res.ok) throw new Error('Status error');
                const data = await res.json();

                const dot = document.getElementById('status-dot');
                const text = document.getElementById('status-text');
                const rssi = document.getElementById('telemetry-rssi');
                const last = document.getElementById('telemetry-last');

                if (data.online) {
                    dot.className = 'status-dot online';
                    text.textContent = 'ESP Online';
                    text.style.color = 'var(--success)';
                } else {
                    dot.className = 'status-dot offline';
                    text.textContent = 'ESP Offline';
                    text.style.color = 'var(--danger)';
                }

                rssi.textContent = data.rssi !== null ? `${data.rssi} dBm` : '-';
                last.textContent = data.last_seen_sec !== null ? `${data.last_seen_sec}s lalu` : 'Tidak aktif';
            } catch (err) {
                console.error(err);
            }
        }

        checkStatus();
        setInterval(checkStatus, 5000);

        // History Loader
        async function loadHistory() {
            try {
                const res = await fetch('/egg/history');
                if (!res.ok) throw new Error('History error');
                const list = await res.json();

                const grid = document.getElementById('history-grid');
                if (list.length === 0) {
                    grid.innerHTML = '<div style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">Belum ada foto yang tersimpan.</div>';
                    return;
                }

                grid.innerHTML = list.map(item => `
                    <div class="history-item">
                        <img src="${item.url}" class="history-thumb" alt="Job #${item.id}" onclick="loadJobDetails(${item.id})">
                        <div class="history-meta">
                            <div>
                                <span class="history-id">#${item.id}</span>
                                ${item.prediction_label ? `<span style="margin-left: 6px; font-size: 10px; padding: 2px 6px; border-radius: 4px; background: ${item.prediction_label === 'fertil' ? '#2ca02c' : (item.prediction_label === 'infertil' ? '#1f77b4' : '#d62728')}; color: white; text-transform: uppercase; font-weight: 700;">${item.prediction_label}</span>` : ''}
                                <div style="color: var(--text-muted);">${new Date(item.at).toLocaleTimeString()}</div>
                            </div>
                            <a href="${item.url}" download="egg-${item.id}.jpg" class="history-dl">Unduh</a>
                        </div>
                    </div>
                `).join('');
            } catch (err) {
                console.error(err);
            }
        }

        async function loadJobDetails(id) {
            try {
                const res = await fetch(`/egg/job/${id}`);
                if (!res.ok) throw new Error('Job fetch error');
                const job = await res.json();
                const pred = job.prediction_result || { label: job.prediction_label, confidence: job.confidence };
                const vis = job.prediction_result ? job.prediction_result.visualisasi : null;
                showInPreview(job.url, pred, job.llm_analysis, vis);
                showCandlingMetrics(job.metrics);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (e) {
                console.error(e);
            }
        }

        loadHistory();

        function showInPreview(url, prediction = null, llm = null, vis = null) {
            const img = document.getElementById('preview-image');
            const placeholder = document.getElementById('preview-placeholder');
            const btnDownload = document.getElementById('btn-download');

            img.src = url;
            img.style.display = 'block';
            placeholder.style.display = 'none';
            btnDownload.href = url;
            btnDownload.style.display = 'inline-flex';

            const resultBox = document.getElementById('ai-result-box');
            if (prediction) {
                resultBox.style.display = 'block';
                const badge = document.getElementById('ai-badge');
                const confText = document.getElementById('ai-confidence');
                const confBar = document.getElementById('ai-confidence-bar');

                const label = (prediction.label || '-').toLowerCase();
                badge.textContent = label;

                if (label === 'fertil') {
                    badge.style.background = '#2ca02c';
                    badge.style.color = '#fff';
                } else if (label === 'infertil') {
                    badge.style.background = '#1f77b4';
                    badge.style.color = '#fff';
                } else if (label === 'mortal') {
                    badge.style.background = '#d62728';
                    badge.style.color = '#fff';
                } else {
                    badge.style.background = 'var(--text-muted)';
                    badge.style.color = '#fff';
                }

                const conf = Math.round((prediction.keyakinan || prediction.confidence || 0) * 100);
                confText.textContent = `${conf}%`;
                confBar.style.width = `${conf}%`;

                if (prediction.probabilitas) {
                    document.getElementById('prob-fertil').textContent = `${Math.round((prediction.probabilitas.fertil || 0) * 100)}%`;
                    document.getElementById('prob-infertil').textContent = `${Math.round((prediction.probabilitas.infertil || 0) * 100)}%`;
                    document.getElementById('prob-mortal').textContent = `${Math.round((prediction.probabilitas.mortal || 0) * 100)}%`;
                }

                // LLM Box
                const llmBox = document.getElementById('llm-box');
                const llmContent = document.getElementById('llm-content');
                if (llm) {
                    llmBox.style.display = 'block';
                    llmContent.textContent = llm;
                } else {
                    llmBox.style.display = 'none';
                }

                // Visualizations (5 Tahap Computer Vision)
                const visBox = document.getElementById('ai-vis-container');
                if (vis) {
                    visBox.style.display = 'block';
                    if (vis.citra_crop) document.getElementById('vis-crop').src = vis.citra_crop;
                    if (vis.citra_biner_roi) document.getElementById('vis-roi').src = vis.citra_biner_roi;
                    if (vis.citra_rekonstruksi_rgb) document.getElementById('vis-masked').src = vis.citra_rekonstruksi_rgb;
                    if (vis.citra_peta_darah) document.getElementById('vis-peta').src = vis.citra_peta_darah;
                    if (vis.citra_jaringan_darah) document.getElementById('vis-jaringan').src = vis.citra_jaringan_darah;
                } else {
                    visBox.style.display = 'none';
                }
            }
        }

        function showCandlingMetrics(metrics, browserMs = null) {
            const output = document.getElementById('candling-metrics');
            if (!metrics) { output.textContent = ''; return; }
            const values = [
                `Ukuran: ${(metrics.image_bytes / 1024).toFixed(1)} KB`,
                `Permintaan Flask (termasuk jaringan): ${metrics.model_request_ms} ms`,
                `Permintaan LLM (termasuk jaringan): ${metrics.llm_request_ms === null ? 'tidak dipanggil' : metrics.llm_request_ms + ' ms'}`,
                `Pemrosesan server sebelum penyimpanan: ${metrics.server_processing_ms} ms`
            ];
            if (browserMs !== null) values.push(`Total browser hingga respons: ${browserMs.toFixed(0)} ms`);
            output.textContent = values.join(' · ');
        }

        document.getElementById('candling-upload-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = document.getElementById('candling-upload-button');
            const message = document.getElementById('candling-upload-message');
            const file = document.getElementById('candling-image').files[0];
            if (!file) return;
            button.disabled = true;
            message.textContent = 'Mengunggah dan menganalisis foto...';
            document.getElementById('candling-metrics').textContent = '';
            const body = new FormData();
            body.append('image', file);
            const started = performance.now();
            try {
                const response = await fetch('/egg/upload', {
                    method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, body
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.errors?.image?.[0] || data.message || 'Unggah gagal.');
                const browserMs = performance.now() - started;
                showInPreview(data.url, data.prediction_result, data.llm_analysis, data.prediction_result?.visualisasi);
                showCandlingMetrics(data.metrics, browserMs);
                message.textContent = data.llm_status === 'failed' ? 'Hasil model tersedia; LLM gagal merespons.' : (data.llm_status === 'not_configured' ? 'Hasil model tersedia; LLM belum dikonfigurasi.' : 'Foto berhasil dianalisis.');
                loadHistory();
            } catch (error) {
                message.textContent = error.message || 'Tidak dapat terhubung ke server.';
            } finally {
                button.disabled = false;
            }
        });

        // Capture Job Dispatch & Tracker
        const btnCapture = document.getElementById('btn-capture');
        const trackerBox = document.getElementById('tracker-box');
        const trackerSpinner = document.getElementById('tracker-spinner');
        const trackerMsg = document.getElementById('tracker-msg');
        const trackerTime = document.getElementById('tracker-time');

        let pollInterval = null;
        let timerInterval = null;
        let startTime = null;

        btnCapture.addEventListener('click', async () => {
            btnCapture.disabled = true;
            trackerBox.style.display = 'block';
            trackerSpinner.style.display = 'block';
            trackerMsg.textContent = 'Membuat job capture...';
            trackerMsg.style.color = 'var(--text-primary)';

            startTime = Date.now();
            clearInterval(timerInterval);
            timerInterval = setInterval(() => {
                const elapsed = Math.floor((Date.now() - startTime) / 1000);
                trackerTime.textContent = `Waktu berlalu: ${elapsed}s / 60s`;
            }, 500);

            try {
                const payload = {
                    exposure: parseInt(document.getElementById('slider-exposure').value, 10),
                    gain: parseInt(document.getElementById('slider-gain').value, 10),
                    quality: parseInt(document.getElementById('slider-quality').value, 10),
                    settle_ms: parseInt(document.getElementById('slider-settle').value, 10)
                };

                const res = await fetch('/egg/capture', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) throw new Error('Gagal membuat job capture');
                const data = await res.json();
                const jobId = data.job;

                trackJob(jobId);
            } catch (err) {
                stopTracking(false, err.message || 'Terjadi kesalahan sistem.');
            }
        });

        function trackJob(jobId) {
            trackerMsg.textContent = 'Menunggu ESP mengambil tugas...';

            clearInterval(pollInterval);
            pollInterval = setInterval(async () => {
                const elapsed = Math.floor((Date.now() - startTime) / 1000);

                if (elapsed > 120) {
                    stopTracking(false, 'Timeout: ESP atau analisis LLM belum selesai dalam 120 detik.');
                    return;
                }

                try {
                    const res = await fetch(`/egg/job/${jobId}`);
                    if (!res.ok) throw new Error('Gagal memeriksa status job');
                    const job = await res.json();

                    if (job.status === 'pending') {
                        trackerMsg.textContent = 'Menunggu ESP mengambil tugas...';
                    } else if (job.status === 'taken') {
                        trackerMsg.textContent = 'ESP sedang Mengambil Foto...';
                    } else if (job.status === 'done') {
                        stopTracking(true, 'Pengambilan Foto & Analisis Selesai!');
                        if (job.url) {
                            const pred = job.prediction_result || { label: job.prediction_label, confidence: job.confidence };
                            const vis = job.prediction_result ? job.prediction_result.visualisasi : null;
                            showInPreview(job.url, pred, job.llm_analysis, vis);
                            showCandlingMetrics(job.metrics);
                        }
                        loadHistory();
                    } else if (job.status === 'failed') {
                        stopTracking(false, 'Pengambilan foto gagal di perangkat ESP.');
                    }
                } catch (err) {
                    console.error(err);
                }
            }, 1500);
        }

        function stopTracking(success, message) {
            clearInterval(pollInterval);
            clearInterval(timerInterval);
            trackerSpinner.style.display = 'none';
            trackerMsg.textContent = message;
            trackerMsg.style.color = success ? 'var(--success)' : 'var(--danger)';
            btnCapture.disabled = false;
        }
    </script>
</body>
</html>
