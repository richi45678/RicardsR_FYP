window.plantMetaByFile = {};

function soilPercent(raw) {
    var v = Math.max(0, Math.min(4095, parseInt(raw, 10) || 0));
    return Math.round((4095 - v) / 4095 * 100);
}
function soilLabelFromRaw(raw) {
    var v = parseInt(raw, 10) || 0;
    if (v < 1500) return 'WET';
    if (v < 3000) return 'MOIST';
    return 'DRY';
}

window.tankCalibration = (window.SG_CONFIG && window.SG_CONFIG.tank) || { emptyCm: 18.5, warningCm: 15, halfCm: 10, fullCm: 1.5 };
function tankLevelPercent(distanceCm) {
    var c = window.tankCalibration;
    if (distanceCm === null || distanceCm === undefined || isNaN(distanceCm) || distanceCm < 0) return null;
    var d = Number(distanceCm);
    if (d >= c.emptyCm) return 0;
    if (d <= c.fullCm) return 100;
    if (d <= c.halfCm) {
        return Math.round(50 + (c.halfCm - d) / (c.halfCm - c.fullCm) * 50);
    }
    return Math.round((c.emptyCm - d) / (c.emptyCm - c.halfCm) * 50);
}
function tankStatusFromDistance(distanceCm) {
    var c = window.tankCalibration;
    if (distanceCm === null || distanceCm === undefined || isNaN(distanceCm) || distanceCm < 0) return 'NO_READING';
    var d = Number(distanceCm);
    if (d >= c.emptyCm) return 'EMPTY';
    if (d >= c.warningCm) return 'LOW';
    if (d >= c.halfCm) return 'HALF';
    return 'OK';
}
function tankStatusLabel(status) {
    if (status === 'EMPTY') return 'Empty';
    if (status === 'LOW') return 'Low';
    if (status === 'HALF') return 'Half';
    if (status === 'OK') return 'OK';
    return 'No reading';
}
function tankUi(distanceCm) {
    var status = tankStatusFromDistance(distanceCm);
    var cls = status.toLowerCase();
    if (cls === 'no_reading') cls = 'noreading';
    if (['empty', 'low', 'half', 'ok'].indexOf(cls) < 0) cls = 'noreading';
    return { cls: cls, pct: tankLevelPercent(distanceCm), label: tankStatusLabel(status), status: status };
}

function showToast(message, type) {
    var stack = document.getElementById('toast-stack');
    if (!stack) return;
    var el = document.createElement('div');
    el.className = 'toast toast-' + (type === 'error' ? 'error' : 'success');
    el.textContent = message;
    stack.appendChild(el);
    setTimeout(function () {
        el.style.opacity = '0';
        el.style.transition = 'opacity 0.3s';
        setTimeout(function () { el.remove(); }, 300);
    }, 5000);
}
if (window.__captureFlash) {
    showToast(
        window.__captureFlash.message,
        window.__captureFlash.success ? 'success' : 'error'
    );
}

function escHtml(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fillPlantPanel(plant, filename) {
    var panel = document.getElementById('plantDetailPanel');
    if (!panel) return;
    if (!plant) {
        panel.innerHTML = '<p class="plant-detail-muted">No analysis yet. Use <strong>Re-run AI analysis</strong> if needed.</p>';
        return;
    }
    if (plant.ok === false || plant.error) {
        panel.innerHTML = '<p class="plant-detail-error">' + escHtml(plant.error || 'Analysis failed') + '</p>';
        return;
    }
    var st = plant.health_status || 'uncertain';
    var pillClass = 'plant-pill-' + String(st).replace(/[^a-z_]/gi, '');
    var h = '<h3 class="plant-title">' + escHtml(plant.plant_name || 'Unknown plant') + '</h3>';
    h += '<p style="margin:0 0 0.5rem;"><span class="plant-pill ' + escHtml(pillClass) + '">' + escHtml(String(st).replace(/_/g, ' ')) + '</span></p>';
    h += '<p class="detail-line"><span class="label">Summary</span>' + escHtml(plant.health_summary || '—') + '</p>';
    var concerns = plant.diseases_or_concerns;
    if (Array.isArray(concerns) && concerns.length) {
        h += '<p class="detail-line"><span class="label">Diseases / concerns</span>' + escHtml(concerns.join('; ')) + '</p>';
    } else if (typeof concerns === 'string' && concerns) {
        h += '<p class="detail-line"><span class="label">Diseases / concerns</span>' + escHtml(concerns) + '</p>';
    }
    h += '<p class="detail-line"><span class="label">Watering estimate</span>' + escHtml(plant.watering_estimate || '—') + '</p>';
    if (plant.disclaimer) {
        h += '<p class="detail-line plant-detail-muted" style="font-size:0.82rem;margin-top:0.75rem;">' + escHtml(plant.disclaimer) + '</p>';
    }
    if (plant.recommended_soil_moisture_min_percent != null) {
        h += '<p class="detail-line"><span class="label">AI moisture target</span>' +
            escHtml(String(plant.recommended_soil_moisture_min_percent)) + '% minimum</p>';
    }
    if (plant.model || plant.analyzed_at) {
        h += '<p class="plant-detail-muted" style="font-size:0.75rem;margin:0.75rem 0 0;">';
        if (plant.model) h += escHtml(plant.model);
        if (plant.model && plant.analyzed_at) h += ' · ';
        if (plant.analyzed_at) h += escHtml(plant.analyzed_at);
        h += '</p>';
    }
    panel.innerHTML = h;
}

function openPhotoModal(filename) {
    var enc = encodeURIComponent(filename);
    var img = document.getElementById('modalImage');
    var fnEl = document.getElementById('modalPlantFilename');
    var panel = document.getElementById('plantDetailPanel');
    img.src = 'uploads/' + enc;
    img.setAttribute('data-current-file', filename);
    if (fnEl) fnEl.textContent = filename;
    document.getElementById('imageModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    var cached = window.plantMetaByFile[filename];
    if (cached) {
        fillPlantPanel(cached, filename);
    } else if (panel) {
        panel.innerHTML = '<p class="plant-detail-loading"><span class="loading" style="border-color:#ccc;border-top-color:#40916c"></span> Loading analysis…</p>';
    }

    fetch('plant_meta.php?name=' + enc, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.ok) {
                window.plantMetaByFile[filename] = data.plant;
                fillPlantPanel(data.plant, filename);
            } else {
                fillPlantPanel(null, filename);
            }
        })
        .catch(function () {
            if (cached) {
                fillPlantPanel(cached, filename);
            } else if (panel) {
                panel.innerHTML = '<p class="plant-detail-error">Could not load analysis. Check your connection and try again.</p>';
            }
        });
}

function closeModal() {
    document.getElementById('imageModal').classList.remove('active');
    document.body.style.overflow = '';
}

(function bindGalleryPhotoOpens() {
    var root = document.getElementById('photo-gallery-root');
    if (!root) return;
    function openFromThumb(thumb) {
        var fn = thumb.getAttribute('data-filename');
        if (!fn) return;
        openPhotoModal(fn);
    }
    root.addEventListener('click', function (e) {
        var thumb = e.target.closest('.photo-thumb-hit');
        if (!thumb || !root.contains(thumb)) return;
        e.preventDefault();
        openFromThumb(thumb);
    });
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var thumb = e.target.closest('.photo-thumb-hit');
        if (!thumb || !root.contains(thumb)) return;
        e.preventDefault();
        openFromThumb(thumb);
    });
})();

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

(function monitoredPlantUi() {
    var form = document.getElementById('monitored-plant-form');
    var statusEl = document.getElementById('mp-status');
    var minInput = document.getElementById('mp-min');
    var suggestedDefault = (window.SG_CONFIG && window.SG_CONFIG.moistureDefault) || 40;

    function updateThresholdDisplays(m) {
        if (!m) return;
        var elMin = document.getElementById('mp-display-min');
        var elAi = document.getElementById('mp-display-ai');
        var elEff = document.getElementById('mp-display-effective');
        if (elMin) elMin.textContent = (m.min_moisture_percent != null ? m.min_moisture_percent : suggestedDefault) + '%';
        if (elAi) elAi.textContent = m.ai_recommended_min_percent != null ? m.ai_recommended_min_percent + '%' : '—';
        if (elEff) elEff.textContent = (m.effective_threshold_percent != null ? m.effective_threshold_percent : suggestedDefault) + '%';
    }

    function updatePlantNameUi(m) {
        var heading = document.getElementById('mp-heading');
        var nameInput = document.getElementById('mp-plant-name');
        var hint = document.getElementById('mp-ai-plant-hint');
        var display = (m && m.plant_name) ? m.plant_name : '';
        var ai = (m && m.ai_plant_name) ? m.ai_plant_name : '';
        if (nameInput && m) nameInput.value = display;
        if (heading) heading.textContent = display || 'Monitored plant';
        if (!hint) return;
        if (ai) {
            var same = display && ai && display === ai;
            hint.innerHTML = 'AI identified: <strong>' + escHtml(ai) + '</strong>';
            if (!same) {
                hint.innerHTML += ' <button type="button" id="mp-use-ai-name">Use AI name</button>';
            }
        } else {
            hint.textContent = 'Run plant AI on this photo to get a suggested name.';
        }
    }

    function applyMonitoredToForm(m) {
        if (!m) return;
        var photo = document.getElementById('mp-photo');
        var esp = document.getElementById('mp-esp');
        var pump = document.getElementById('mp-pump');
        var en = document.getElementById('mp-enabled');
        if (photo) photo.value = m.photo_filename || '';
        if (esp && m.esp_id) esp.value = m.esp_id;
        if (minInput) minInput.value = m.min_moisture_percent != null ? m.min_moisture_percent : suggestedDefault;
        if (pump) pump.value = m.pump_duration_sec != null ? m.pump_duration_sec : 5;
        if (en) en.checked = !!m.enabled;
        var imgBox = document.getElementById('monitored-photo-box');
        if (imgBox && m.photo_url) {
            imgBox.innerHTML = '<img src="' + m.photo_url.replace(/"/g, '&quot;') + '" alt="Monitored plant" id="monitored-photo-img">';
        }
        updateThresholdDisplays(m);
        updatePlantNameUi(m);
    }

    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'mp-use-ai-name') {
            var hint = document.getElementById('mp-ai-plant-hint');
            var strong = hint && hint.querySelector('strong');
            var ai = strong ? strong.textContent : '';
            var nameInput = document.getElementById('mp-plant-name');
            if (nameInput && ai) {
                nameInput.value = ai;
                var heading = document.getElementById('mp-heading');
                if (heading) heading.textContent = ai;
            }
        }
    });

    window.setMonitoredPhotoFilename = function (filename) {
        var photo = document.getElementById('mp-photo');
        if (photo) photo.value = filename;
        var imgBox = document.getElementById('monitored-photo-box');
        if (imgBox && filename) {
            imgBox.innerHTML = '<img src="uploads/' + encodeURIComponent(filename) + '" alt="Monitored plant">';
        }
        fetch('plant_meta.php?name=' + encodeURIComponent(filename), { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var ai = (data.ok && data.plant && data.plant.plant_name) ? data.plant.plant_name : '';
                var nameInput = document.getElementById('mp-plant-name');
                if (ai && nameInput && !nameInput.value.trim()) {
                    nameInput.value = ai;
                }
                updatePlantNameUi({
                    plant_name: nameInput ? nameInput.value : '',
                    ai_plant_name: ai
                });
            })
            .catch(function () {
                updatePlantNameUi({ plant_name: '', ai_plant_name: '' });
            });
        if (statusEl) statusEl.textContent = 'Photo selected — set name, ESP, and save.';
        var section = document.getElementById('monitored-plant');
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(form);
            fd.set('action', 'save');
            if (!document.getElementById('mp-enabled').checked) {
                fd.delete('enabled');
            } else {
                fd.set('enabled', '1');
            }
            if (statusEl) statusEl.textContent = 'Saving…';
            fetch('monitored_plant_api.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.error || 'save failed');
                    applyMonitoredToForm(data.monitored);
                    var rev = data.monitored.config_revision != null ? data.monitored.config_revision : '?';
                    var trig = data.monitored.effective_threshold_percent != null ? data.monitored.effective_threshold_percent : '?';
                    var esp = data.monitored.esp_id || '';
                    if (statusEl) {
                        statusEl.textContent = 'Saved config #' + rev + ' — ESP ' + esp + ' will pump below ' + trig + '% (polls Azure ~every 2s; flash firmware with CONFIG_POLL if needed).';
                    }
                    if (typeof showToast === 'function') {
                        showToast('Saved: pump below ' + trig + '% (config #' + rev + ')', 'success');
                    }
                })
                .catch(function (err) {
                    if (statusEl) statusEl.textContent = 'Error: ' + err.message;
                });
        });
    }

    var clearBtn = document.getElementById('mp-clear');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!confirm('Clear monitored plant settings?')) return;
            var fd = new FormData();
            fd.set('action', 'clear');
            fetch('monitored_plant_api.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function () { location.reload(); });
        });
    }

    var setBtn = document.getElementById('setMonitoredPlantBtn');
    if (setBtn) {
        setBtn.addEventListener('click', function () {
            var img = document.getElementById('modalImage');
            var fn = img && img.getAttribute('data-current-file');
            if (!fn) return;
            window.setMonitoredPhotoFilename(fn);
            if (typeof closeModal === 'function') closeModal();
        });
    }

    if (minInput) {
        minInput.addEventListener('change', function () {
            var v = parseInt(minInput.value, 10);
            if (isNaN(v) || v < 0) minInput.value = 0;
            if (v > 100) minInput.value = 100;
        });
    }
})();

(function plantReanalyzeBtn() {
    var btn = document.getElementById('plantReanalyzeBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var img = document.getElementById('modalImage');
        var panel = document.getElementById('plantDetailPanel');
        var fn = img && img.getAttribute('data-current-file');
        if (!fn) return;
        btn.disabled = true;
        var prevLabel = btn.textContent;
        btn.textContent = 'Running analysis…';
        if (panel) {
            panel.innerHTML = '<p class="plant-detail-loading"><span class="loading" style="border-color:#ddd;border-top-color:#40916c"></span> Calling AI…</p>';
        }
        var fd = new FormData();
        fd.append('filename', fn);
        fetch('plant_analyze_api.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.plant) {
                    window.plantMetaByFile[fn] = data.plant;
                    fillPlantPanel(data.plant, fn);
                    if (typeof window.triggerGalleryRefresh === 'function') {
                        window.triggerGalleryRefresh();
                    }
                } else {
                    fillPlantPanel({ ok: false, error: (data && data.error) ? data.error : 'failed' }, fn);
                }
            })
            .catch(function () {
                fillPlantPanel({ ok: false, error: 'network error' }, fn);
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = prevLabel;
            });
    });
})();

document.querySelectorAll('.capture-form').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('.capture-btn');
        btn.innerHTML = '<span class="loading"></span><span>Capturing…</span>';
        // Defer disable: disabling the submitter synchronously can abort the POST
        setTimeout(function() { btn.disabled = true; }, 0);
    });
});


(function sensorLiveUpdates() {
    var emptyMsg = '<div class="empty-state sensor-empty-msg"><strong>No sensor data</strong>Waiting for ESP32 nodes to report in.</div>';
    var root = document.getElementById('sensor-nodes-dynamic');
    if (!root) return;

    function esc(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function tankDetailLabel(d) {
        var t = tankUi(d);
        if (t.pct === null) return 'No reading';
        var s = esc(String(t.pct)) + '%';
        if (d !== undefined && d !== null && !isNaN(Number(d)) && Number(d) >= 0) {
            s += ' <span style="font-weight:400;color:#5c6f66;">(' + esc(String(Number(d).toFixed(1))) + ' cm)</span>';
        }
        return s;
    }
    function soilUi(label, soilRaw) {
        var L = soilLabelFromRaw(soilRaw);
        var cls = 'moist';
        if (L === 'DRY') { cls = 'dry'; }
        else if (L === 'WET') { cls = 'wet'; }
        return { cls: cls, pct: soilPercent(soilRaw), label: L };
    }
    function di(lbl, val) {
        return '<div class="detail-item"><span class="detail-label">' + lbl + '</span><span class="detail-value">' + val + '</span></div>';
    }
    function render(data) {
        if (!data || !data.devices) return;
        var devs = Object.keys(data.devices).map(function(k) { return data.devices[k]; });
        if (!devs.length) {
            root.innerHTML = emptyMsg;
            return;
        }
        var h = '<div class="cards-grid">';
        devs.forEach(function(dev) {
            var soil = soilUi(dev.soil_label, dev.soil_raw);
            var tank = tankUi(dev.distance_cm);
            var pumpOn = !!dev.pump_running;
            var rssi = (dev.rssi !== undefined && dev.rssi !== null) ? (parseInt(dev.rssi, 10) + ' dBm') : 'N/A';
            h += '<div class="device-card">';
            h += '<div class="device-card-header"><span class="device-name">Node ' + esc(dev.id) + '</span>';
            h += '<span class="status-badge status-online">Live</span></div>';
            h += '<div class="soil-block"><div class="soil-label-row">';
            h += '<span class="detail-label">Soil moisture</span>';
            h += '<span class="soil-tag soil-tag-' + esc(soil.cls) + '">' + esc(soil.label) + '</span></div>';
            h += '<div class="soil-bar-track"><div class="soil-bar-fill soil-' + esc(soil.cls) + '" style="width:' + soil.pct + '%"></div></div></div>';
            h += '<div class="tank-block"><div class="soil-label-row">';
            h += '<span class="detail-label">Water tank</span>';
            h += '<span class="soil-tag tank-tag-' + esc(tank.cls) + '">' + esc(tank.label) + '</span></div>';
            h += '<div class="soil-bar-track"><div class="tank-bar-fill tank-' + esc(tank.cls) + '" style="width:' + (tank.pct !== null ? tank.pct : 0) + '%"></div></div></div>';
            h += '<div class="device-details">';
            h += '<div class="detail-item"><span class="detail-label">Moisture</span><span class="detail-value">' +
                esc(String(soilPercent(dev.soil_raw))) + '% <span style="font-weight:400;color:#5c6f66;">(ADC ' +
                esc(String(parseInt(dev.soil_raw || 0, 10))) + ')</span></span></div>';
            h += '<div class="detail-item"><span class="detail-label">Tank level</span><span class="detail-value">' + tankDetailLabel(dev.distance_cm) + '</span></div>';
            h += di('Last update', esc(dev.updated_at || '—'));
            var pumpHtml = '<span class="pump-pill ' + (pumpOn ? 'pump-on' : 'pump-off') + '">' + (pumpOn ? 'Running' : 'Off') + '</span>';
            if (!pumpOn && tank.status === 'EMPTY') {
                pumpHtml += ' <span class="soil-tag tank-tag-empty" style="margin-left:0.35rem;">Blocked</span>';
            }
            h += di('Pump', pumpHtml);
            h += di('WiFi', esc(rssi));
            h += '</div></div>';
        });
        h += '</div>';
        root.innerHTML = h;
    }
    function tick() {
        fetch('sensor_api.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                render(data);
            })
            .catch(function() {});
    }
    tick();
    setInterval(tick, 4000);
})();

(function sensorHistoryCharts() {
    if (typeof Chart === 'undefined') return;

    var select = document.getElementById('history-device');
    var statusEl = document.getElementById('history-status');
    var rangeBtns = document.querySelectorAll('.history-range');
    var hours = 24;
    var charts = { soil: null, distance: null, pump: null };

    var chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1b4332',
                titleFont: { size: 12 },
                bodyFont: { size: 12 },
            },
        },
        scales: {
            x: {
                ticks: { maxTicksLimit: 8, font: { size: 10 } },
                grid: { color: 'rgba(0,0,0,0.06)' },
            },
            y: {
                grid: { color: 'rgba(0,0,0,0.06)' },
            },
        },
    };

    function formatLabel(ts, useDays) {
        var d = new Date(ts * 1000);
        if (useDays) {
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' ' +
                d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function mergeDeviceOptions(ids) {
        if (!select || !ids || !ids.length) return;
        var existing = {};
        Array.prototype.forEach.call(select.options, function (o) {
            if (o.value) existing[o.value] = true;
        });
        ids.forEach(function (id) {
            if (!id || existing[id]) return;
            var opt = document.createElement('option');
            opt.value = id;
            opt.textContent = id;
            select.appendChild(opt);
            existing[id] = true;
        });
    }

    function destroyCharts() {
        Object.keys(charts).forEach(function (k) {
            if (charts[k]) {
                charts[k].destroy();
                charts[k] = null;
            }
        });
    }

    function buildChart(canvasId, label, data, color, yOpts) {
        var el = document.getElementById(canvasId);
        if (!el) return null;
        var opts = JSON.parse(JSON.stringify(chartDefaults));
        if (yOpts) {
            Object.assign(opts.scales.y, yOpts);
        }
        return new Chart(el, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: label,
                    data: data.values,
                    borderColor: color,
                    backgroundColor: color.replace('rgb(', 'rgba(').replace(')', ', 0.15)'),
                    fill: true,
                    tension: 0.25,
                    pointRadius: data.values.length > 80 ? 0 : 2,
                    borderWidth: 2,
                }],
            },
            options: opts,
        });
    }

    function renderCharts(points) {
        destroyCharts();
        if (!points.length) {
            if (statusEl) statusEl.textContent = 'No history for this range yet — wait for sensor uploads.';
            return;
        }

        var useDays = hours >= 48;
        var labels = points.map(function (p) { return formatLabel(p.t, useDays); });
        var soilVals = points.map(function (p) { return soilPercent(p.soil_raw); });
        var distVals = points.map(function (p) {
            var d = p.distance_cm;
            if (d === null || d === undefined || Number(d) < 0) return null;
            return tankLevelPercent(Number(d));
        });
        var pumpVals = points.map(function (p) { return p.pump ? 1 : 0; });

        var soilOpts = JSON.parse(JSON.stringify(chartDefaults));
        soilOpts.scales.y.min = 0;
        soilOpts.scales.y.max = 100;
        soilOpts.scales.y.ticks = { callback: function (v) { return v + '%'; } };
        var soilEl = document.getElementById('chartSoil');
        if (soilEl) {
            charts.soil = new Chart(soilEl, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Moisture %',
                        data: soilVals,
                        borderColor: 'rgb(45, 106, 79)',
                        backgroundColor: 'rgba(45, 106, 79, 0.15)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: soilVals.length > 80 ? 0 : 2,
                        borderWidth: 2,
                    }],
                },
                options: soilOpts,
            });
        }
        var tankOpts = JSON.parse(JSON.stringify(chartDefaults));
        tankOpts.scales.y.min = 0;
        tankOpts.scales.y.max = 100;
        tankOpts.scales.y.ticks = { callback: function (v) { return v + '%'; } };
        var tankEl = document.getElementById('chartDistance');
        if (tankEl) {
            charts.distance = new Chart(tankEl, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Tank %',
                        data: distVals,
                        borderColor: 'rgb(29, 53, 87)',
                        backgroundColor: 'rgba(29, 53, 87, 0.12)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: distVals.length > 80 ? 0 : 2,
                        borderWidth: 2,
                        spanGaps: true,
                    }],
                },
                options: tankOpts,
            });
        }

        var pumpEl = document.getElementById('chartPump');
        if (pumpEl) {
            var pumpOpts = JSON.parse(JSON.stringify(chartDefaults));
            pumpOpts.scales.y.min = 0;
            pumpOpts.scales.y.max = 1;
            pumpOpts.scales.y.ticks = {
                stepSize: 1,
                callback: function (v) { return v ? 'On' : 'Off'; },
            };
            charts.pump = new Chart(pumpEl, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Pump',
                        data: pumpVals,
                        backgroundColor: 'rgba(233, 196, 106, 0.75)',
                        borderColor: 'rgb(188, 108, 37)',
                        borderWidth: 1,
                    }],
                },
                options: pumpOpts,
            });
        }

        if (statusEl) {
            statusEl.textContent = points.length + ' sample(s) over the last ' +
                (hours >= 168 ? '7 days' : hours + ' hour' + (hours === 1 ? '' : 's'));
        }
    }

    function loadHistory() {
        if (!select || !select.value) {
            destroyCharts();
            if (statusEl) statusEl.textContent = 'No device selected.';
            return;
        }
        if (statusEl) statusEl.textContent = 'Loading history…';
        var url = 'sensor_history.php?device=' + encodeURIComponent(select.value) + '&hours=' + hours;
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'failed');
                renderCharts(data.points || []);
            })
            .catch(function (e) {
                destroyCharts();
                if (statusEl) statusEl.textContent = 'Could not load history: ' + e.message;
            });
    }

    if (select) {
        select.addEventListener('change', loadHistory);
    }
    rangeBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            rangeBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            hours = parseInt(btn.getAttribute('data-hours'), 10) || 24;
            loadHistory();
        });
    });

    setInterval(function () {
        fetch('sensor_history.php?action=devices', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok && data.devices) mergeDeviceOptions(data.devices);
            })
            .catch(function () {});
    }, 30000);

    if (select && select.value) {
        loadHistory();
    }
    setInterval(loadHistory, 60000);

    window.refreshSensorHistoryCharts = loadHistory;

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            ['soil', 'distance', 'pump'].forEach(function (key) {
                if (charts[key]) charts[key].resize();
            });
        }, 150);
    });
    window.addEventListener('orientationchange', function () {
        setTimeout(function () {
            ['soil', 'distance', 'pump'].forEach(function (key) {
                if (charts[key]) charts[key].resize();
            });
        }, 300);
    });
})();

(function gallerySync() {
    var root = document.getElementById('photo-gallery-root');
    var statusEl = document.getElementById('gallery-status-msg');
    var btnRefresh = document.getElementById('gallery-refresh-btn');
    var btnAll = document.getElementById('gallery-select-all');
    var btnNone = document.getElementById('gallery-select-none');
    var btnDelSel = document.getElementById('gallery-delete-selected');
    var btnDelAll = document.getElementById('gallery-delete-all');
    if (!root) return;

    var POLL_MS = 8000;
    var lastJson = '';

    function saveScroll() {
        return window.scrollY || document.documentElement.scrollTop || 0;
    }
    function restoreScroll(y) {
        requestAnimationFrame(function () { window.scrollTo(0, y); });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function updateDeleteBtn() {
        if (!btnDelSel) return;
        var n = root.querySelectorAll('.photo-cb:checked').length;
        btnDelSel.disabled = n === 0;
    }
    window.galleryOnCheckboxChange = updateDeleteBtn;

    function render(items) {
        var y = saveScroll();
        window.plantMetaByFile = {};
        if (!items.length) {
            root.innerHTML = '<div class="empty-state gallery-empty-msg"><strong>No photos yet</strong>Capture from a camera above when it is online.</div>';
            restoreScroll(y);
            updateDeleteBtn();
            var badge = document.getElementById('gallery-count-badge');
            if (badge) badge.textContent = '0';
            return;
        }
        var h = '<div class="gallery-grid" id="photo-gallery-grid">';
        items.forEach(function (it) {
            var name = it.name;
            if (it.plant) {
                window.plantMetaByFile[name] = it.plant;
            }
            var enc = encodeURIComponent(name);
            var t = new Date((it.mtime || 0) * 1000);
            var timeStr = ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2);
            var kb = Math.round((it.size || 0) / 1024);
            var p = it.plant;
            h += '<div class="photo-item" data-filename="' + esc(name) + '">';
            h += '<label class="photo-check-label" onclick="event.stopPropagation();"><input type="checkbox" class="photo-cb" value="' + esc(name) + '" onclick="event.stopPropagation(); if (window.galleryOnCheckboxChange) window.galleryOnCheckboxChange();"></label>';
            h += '<div class="photo-thumb-hit" data-filename="' + esc(name) + '" role="button" tabindex="0" title="Enlarge">';
            h += '<img src="uploads/' + enc + '" loading="lazy" alt="">';
            h += '<div class="photo-overlay">';
            if (p && p.plant_name && (!p.ok || p.ok !== false)) {
                h += '<div class="plant-line">' + esc(p.plant_name) + '</div>';
                if (p.health_status) {
                    var st = String(p.health_status).replace(/[^a-z_]/gi, '');
                    h += '<span class="plant-pill plant-pill-' + esc(st) + '">' + esc(String(p.health_status).replace(/_/g, ' ')) + '</span>';
                }
            } else if (p && p.ok === false) {
                h += '<div class="plant-line" style="color:#ffb4b4;">AI: error</div>';
            }
            h += '<div>' + esc(timeStr) + '</div><div>' + kb + ' KB</div>';
            h += '</div></div></div>';
        });
        h += '</div>';
        root.innerHTML = h;
        restoreScroll(y);
        updateDeleteBtn();
    }

    function refresh() {
        var y = saveScroll();
        fetch('list_uploads.php?limit=48', { cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (data) {
                var json = JSON.stringify(data);
                if (json === lastJson) {
                    restoreScroll(y);
                    return;
                }
                lastJson = json;
                render(data);
                if (statusEl) statusEl.textContent = data.length ? (data.length + ' photo' + (data.length === 1 ? '' : 's') + ' — auto-refreshing') : '';
                var badge = document.getElementById('gallery-count-badge');
                if (badge) badge.textContent = String(data.length);
            })
            .catch(function (e) {
                if (statusEl) statusEl.textContent = 'Could not refresh gallery: ' + e.message;
                restoreScroll(y);
            });
    }

    if (btnRefresh) btnRefresh.addEventListener('click', function () { refresh(); });
    if (btnAll) btnAll.addEventListener('click', function () {
        root.querySelectorAll('.photo-cb').forEach(function (cb) { cb.checked = true; });
        updateDeleteBtn();
    });
    if (btnNone) btnNone.addEventListener('click', function () {
        root.querySelectorAll('.photo-cb').forEach(function (cb) { cb.checked = false; });
        updateDeleteBtn();
    });
    function postDelete(body) {
        return fetch('delete_photos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }
    if (btnDelSel) btnDelSel.addEventListener('click', function () {
        var selected = Array.prototype.map.call(root.querySelectorAll('.photo-cb:checked'), function (cb) { return cb.value; });
        if (!selected.length) return;
        if (!confirm('Delete ' + selected.length + ' selected photo(s)?')) return;
        var params = new URLSearchParams();
        selected.forEach(function (name) { params.append('selected[]', name); });
        postDelete(params.toString()).then(function (res) {
            if (res.errors && res.errors.length && statusEl) {
                statusEl.textContent = 'Some files could not be removed: ' + res.errors.join(', ');
            }
            lastJson = '';
            return refresh();
        }).catch(function (e) {
            if (statusEl) statusEl.textContent = 'Delete failed: ' + e.message;
        });
    });
    if (btnDelAll) btnDelAll.addEventListener('click', function () {
        if (!confirm('Delete ALL photos in uploads? This cannot be undone.')) return;
        postDelete('delete_all=1').then(function (res) {
            if (res.errors && res.errors.length && statusEl) {
                statusEl.textContent = 'Some files could not be removed: ' + res.errors.join(', ');
            }
            lastJson = '';
            return refresh();
        }).catch(function (e) {
            if (statusEl) statusEl.textContent = 'Delete failed: ' + e.message;
        });
    });

    setInterval(refresh, POLL_MS);
    updateDeleteBtn();
    window.triggerGalleryRefresh = refresh;
})();

(function tankNotifyUi() {
    var form = document.getElementById('tank-notify-form');
    var statusEl = document.getElementById('notify-status');
    var testBtn = document.getElementById('notify-test-btn');
    if (!form) return;

    function setStatus(msg) {
        if (statusEl) statusEl.textContent = msg;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        fd.set('action', 'save');
        if (!document.getElementById('notify-enabled').checked) {
            fd.delete('enabled');
        } else {
            fd.set('enabled', '1');
        }
        setStatus('Saving…');
        fetch('tank_notifications_api.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'save failed');
                var n = data.notifications || {};
                var parts = ['Alert settings saved.'];
                if (n.last_low_sent) parts.push('Last low: ' + n.last_low_sent + ' UTC.');
                if (n.last_empty_sent) parts.push('Last empty: ' + n.last_empty_sent + ' UTC.');
                setStatus(parts.join(' '));
                if (typeof showToast === 'function') {
                    showToast('Email alerts saved', 'success');
                }
            })
            .catch(function (err) {
                setStatus('Error: ' + err.message);
                if (typeof showToast === 'function') {
                    showToast(err.message, 'error');
                }
            });
    });

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var email = document.getElementById('notify-email').value.trim();
            if (!email) {
                setStatus('Enter an email address first.');
                return;
            }
            var fd = new FormData();
            fd.set('action', 'test');
            fd.set('email', email);
            setStatus('Sending test email…');
            testBtn.disabled = true;
            fetch('tank_notifications_api.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) throw new Error(data.message || data.error || 'failed');
                    setStatus(data.message || 'Test sent.');
                    if (typeof showToast === 'function') {
                        showToast(data.message || 'Test email sent', 'success');
                    }
                })
                .catch(function (err) {
                    setStatus(err.message);
                    if (typeof showToast === 'function') {
                        showToast(err.message, 'error');
                    }
                })
                .finally(function () {
                    testBtn.disabled = false;
                });
        });
    }
})();
