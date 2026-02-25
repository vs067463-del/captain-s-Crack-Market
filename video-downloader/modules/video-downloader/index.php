<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Video Downloader</title>
    <link rel="stylesheet" href="../../assets/xp/xp-ui.css">
    <style>
        body {
            background-color: #ECE9D8;
            padding: 10px;
            font-family: 'Tahoma', sans-serif;
            font-size: 11px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
        }

        .input-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .input-group input[type="text"] {
            flex: 1;
            padding: 4px;
            border: 1px solid #7F9DB9;
        }

        .btn {
            min-width: 80px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Queue Styles */
        .queue-area {
            flex: 1;
            background: #fff;
            border: 1px solid #7F9DB9;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .queue-item {
            padding: 6px;
            border-bottom: 1px solid #eee;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .queue-item:last-child {
            border-bottom: none;
        }

        .queue-item.active {
            background-color: #f0f8ff;
        }

        .queue-item.done {
            background-color: #efffef;
        }

        .queue-item.error {
            background-color: #fff0f0;
        }

        .q-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .q-title {
            font-weight: bold;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 70%;
        }

        .q-status {
            color: #666;
            font-size: 10px;
        }

        /* Dynamic Progress Container */
        .progress-container {
            height: 15px;
            background: #fff;
            border: 1px solid #7F9DB9;
            position: relative;
            margin-top: 2px;
            padding: 1px;
        }

        .progress-bar {
            height: 100%;
            width: 0%;
            display: flex;
        }

        .progress-text {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            text-align: center;
            line-height: 13px;
            font-size: 10px;
            text-shadow: 1px 1px 0 #fff;
            z-index: 2;
        }

        .controls-area {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 5px;
            background: #f5f5f5;
            border-bottom: 1px solid #ccc;
        }

        .log-area {
            height: 80px;
            background: white;
            border: 1px solid #7F9DB9;
            padding: 4px;
            overflow-y: auto;
            font-family: monospace;
            white-space: pre-wrap;
            display: none;
        }

        #preview-area {
            display: none;
            padding: 10px;
            background: #fff;
            border: 1px solid #ccc;
        }
    </style>
</head>

<body>

    <div class="input-group">
        <input type="text" id="video-url" placeholder="Paste YouTube URL here..." class="form-control">
        <button class="btn btn-xp" onclick="checkUrl()">Check</button>
    </div>

    <div class="controls-area">
        <div class="checkbox-group">
            <input type="checkbox" id="audio-only">
            <label for="audio-only">Audio Only (MP3)</label>
        </div>
        <button class="btn btn-xp" onclick="openDownloadFolder()" style="margin-left: 10px;">Open Folder</button>
        <div style="flex:1"></div>
        <button class="btn btn-xp" onclick="addToQueue()" id="btn-add">Add to Queue</button>
    </div>

    <div id="preview-area">
        <div style="display:flex; gap:10px;">
            <img id="thumb" src="" style="height:64px; border:1px solid #000;">
            <div style="flex:1">
                <div id="video-title" style="font-weight:bold;"></div>
                <div id="video-duration"></div>
                <select id="quality-select" style="margin-top:5px; width:100%; display:none;"></select>
                <button class="btn btn-xp" style="margin-top:5px; width:100%;" onclick="addToQueue()">Download
                    Now</button>
            </div>
        </div>
    </div>

    <div class="queue-area" id="queue-list">
        <div style="padding:10px; color:#999; text-align:center;">Queue is empty</div>
    </div>

    <div class="log-area" id="log-area"></div>

    <div class="status-bar" id="status-bar">Ready.</div>

    <script>
        const statusEl = document.getElementById('status-bar');
        const logArea = document.getElementById('log-area');
        const queueList = document.getElementById('queue-list');

        let queue = [];
        let isProcessing = false;

        // Init: Check download folder & Load Queue
        window.addEventListener('load', async () => {
            loadQueue();
            if (window.parent && window.parent.WebOS && window.parent.WebOS.fs) {
                const fs = window.parent.WebOS.fs;
                const folderPath = '/ftp/VideoDownloads';
                try {
                    await fs.list(folderPath); // Simple check if exists
                    console.log('Download folder exists');
                } catch (e) {
                    console.warn('Folder missing, creating...', e);
                    try {
                        await fs.createDirectory(folderPath);
                        log('Created download directory: ' + folderPath);
                    } catch (err) {
                        log('Failed to create download directory: ' + err.message);
                    }
                }
            }
        });

        function loadQueue() {
            const stored = localStorage.getItem('vd_queue');
            if (stored) {
                try {
                    queue = JSON.parse(stored);

                    // Reset 'processing' tasks to 'pending' or 'error' on reload (since process died)
                    queue.forEach(t => {
                        if (t.status === 'processing') {
                            t.status = 'error';
                            t.errorMsg = 'Interrupted by reload';
                        }
                    });

                    renderQueue();
                } catch (e) { console.error('Failed to load queue', e); }
            }
        }

        function saveQueue() {
            localStorage.setItem('vd_queue', JSON.stringify(queue));
        }

        function log(msg) {
            const div = document.createElement('div');
            div.textContent = msg;
            logArea.appendChild(div);
            logArea.scrollTop = logArea.scrollHeight;
        }

        async function checkUrl() {
            const url = document.getElementById('video-url').value;
            if (!url) {
                statusEl.innerText = 'Error: Please enter a URL';
                log('Error: Please enter a URL');
                return;
            }

            statusEl.innerText = 'Checking URL...';
            document.getElementById('preview-area').style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'formats');
            formData.append('url', url);

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error(text);
                }

                if (data.error) throw new Error(data.error);

                document.getElementById('video-title').innerText = data.title;
                document.getElementById('video-duration').innerText = data.duration;
                document.getElementById('thumb').src = data.thumbnail;
                document.getElementById('preview-area').style.display = 'block';
                statusEl.innerText = 'URL Validated';

                // Populate/Reset formats
                const sel = document.getElementById('quality-select');
                sel.innerHTML = '<option value="">Best (Auto)</option>';
                if (data.formats && data.formats.length) {
                    data.formats.forEach(f => {
                        const opt = document.createElement('option');
                        opt.value = f.id;
                        opt.textContent = f.label;
                        sel.appendChild(opt);
                    });
                    sel.style.display = 'inline-block';
                }
            } catch (e) {
                statusEl.innerText = 'Error checking URL';
                log('Check Error: ' + e.message);
            }
        }

        function addToQueue() {
            const url = document.getElementById('video-url').value;
            if (!url) {
                statusEl.innerText = 'Error: Enter URL first';
                return;
            }

            const title = document.getElementById('video-title').innerText || url;
            const thumb = document.getElementById('thumb').src;
            const format = document.getElementById('quality-select').value;
            const audioOnly = document.getElementById('audio-only').checked;

            const task = {
                id: Date.now(),
                url, title, thumb, format, audioOnly,
                status: 'pending',
                progress: 0
            };

            queue.push(task);
            saveQueue();
            renderQueue();

            // Clear inputs
            document.getElementById('video-url').value = '';
            document.getElementById('preview-area').style.display = 'none';
            document.getElementById('audio-only').checked = false;

            if (!isProcessing) processQueue();
        }

        // --- Dynamic Progress Logic (AnimGraph Integration) ---
        function getLoadingStyle() {
            // Check if AnimGraph is available in parent context
            if (window.parent && window.parent.AnimGraph && window.parent.AnimGraph.getLoadingConfig) {
                return window.parent.AnimGraph.getLoadingConfig();
            }
            // Fallback default (XP style)
            return { style: 'bar95', options: { color: '#00ccff', height: 8, segment: 8 } };
        }

        function buildProgressHtml(percent, config) {
            const style = config.style || 'bar95';
            const opt = config.options || {};

            let barStyle = `width:${percent}%; height:100%; transition: width 0.2s;`;

            // Map styles based on AnimGraph logic
            if (style === 'bar95') {
                const seg = Math.max(4, Number(opt.segment) || 8);
                const color = opt.color || '#00ccff';
                // XP Block style via CSS gradient
                barStyle += `background-color: ${color}; background-image: repeating-linear-gradient(90deg, rgba(255,255,255,0.25) 0 ${Math.floor(seg / 2)}px, rgba(0,0,0,0.12) ${Math.floor(seg / 2)}px ${seg}px);`;
            } else if (style === 'bar' || style === 'solid') {
                // Simple solid bar
                barStyle += `background-color: ${opt.color || '#00ccff'};`;
            } else if (style === 'scanline') {
                // Scanline style
                barStyle += `background-color: ${opt.color || '#00ccff'};`;
            } else {
                // Generic fallback
                barStyle += `background-color: ${opt.color || '#00ccff'};`;
            }

            return `<div class="progress-bar" style="${barStyle}"></div>`;
        }

        function renderQueue() {
            if (queue.length === 0) {
                queueList.innerHTML = '<div style="padding:10px; color:#999; text-align:center;">Queue is empty</div>';
                return;
            }
            queueList.innerHTML = '';

            // Get current system loading style
            const cfg = getLoadingStyle();

            queue.slice().reverse().forEach(task => { // Newest on top
                const div = document.createElement('div');
                div.className = `queue-item ${task.status}`;
                div.id = `task-${task.id}`;

                let btns = '';
                if (task.status === 'done' && task.filename) {
                    const safeFn = task.filename.replace(/'/g, "\\'");
                    btns = `<button class="btn" style="min-width:40px; padding:2px;" onclick="watchVideo('${safeFn}')">Watch</button>`;
                }

                // Remove button
                btns += ` <button class="btn" style="min-width:20px; padding:0 4px;" onclick="removeTask(${task.id})">x</button>`;

                // Generate Progress Bar HTML
                const progHtml = buildProgressHtml(task.progress, cfg);

                div.innerHTML = `
                    <div class="q-row">
                        <div class="q-title" title="${task.title}">${task.title}</div>
                        <div class="q-status">${task.status}</div>
                    </div>
                    <div class="q-row">
                        <div style="font-size:10px; color:#888;">${task.audioOnly ? 'Audio Only' : 'Video'}</div>
                        <div>${btns}</div>
                    </div>
                    <div class="progress-container">
                        ${progHtml}
                        <div class="progress-text">${Math.round(task.progress)}%</div>
                    </div>
                `;
                queueList.appendChild(div);
            });
        }

        function removeTask(id) {
            queue = queue.filter(t => t.id !== id);
            saveQueue();
            renderQueue();
        }

        async function processQueue() {
            if (queue.length === 0) {
                isProcessing = false;
                statusEl.innerText = 'Queue finished.';
                return;
            }

            const task = queue.find(t => t.status === 'pending');
            if (!task) {
                isProcessing = false;
                statusEl.innerText = 'All tasks done.';
                return;
            }

            isProcessing = true;
            task.status = 'processing';
            task.progress = 0;
            saveQueue();
            renderQueue();

            statusEl.innerText = `Downloading: ${task.title}`;

            const formData = new FormData();
            formData.append('action', 'download');
            formData.append('url', task.url);
            if (task.format) formData.append('format', task.format);
            if (task.audioOnly) formData.append('audioOnly', 'true');

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const reader = res.body.getReader();
                const decoder = new TextDecoder("utf-8");
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.trim()) continue;
                        try {
                            const msg = JSON.parse(line);
                            if (msg.status === 'progress') {
                                task.progress = msg.percent;
                                updateTaskProgress(task.id, msg.percent);
                            } else if (msg.status === 'finished') {
                                task.status = 'done';
                                task.filename = msg.filename;
                                task.fullPath = msg.full_path;
                                task.progress = 100;
                                saveQueue();
                            } else if (msg.status === 'error') {
                                task.status = 'error';
                                log('Error Task: ' + msg.msg);
                                saveQueue();
                            }
                        } catch (e) { }
                    }
                }
                renderQueue(); // Final update
            } catch (e) {
                task.status = 'error';
                log('Network Error: ' + e.message);
                saveQueue();
                renderQueue();
            }

            setTimeout(processQueue, 1000);
        }

        function updateTaskProgress(taskId, percent) {
            const el = document.getElementById(`task-${taskId}`);
            if (el) {
                // Update bar width directly for existing element
                const bar = el.querySelector('.progress-bar');
                const text = el.querySelector('.progress-text');

                if (bar) {
                    bar.style.width = percent + '%';
                }
                if (text) text.innerText = Math.round(percent) + '%';
            }
        }

        function watchVideo(filename) {
            if (!filename) return;
            const fileObj = {
                name: filename,
                dir: false,
                path: '/VideoDownloads/' + filename
            };

            if (window.parent && window.parent.WebOS && window.parent.WebOS.registry) {
                window.parent.WebOS.registry.open(fileObj);
            } else {
                alert('Registry not found/available');
            }
        }

        function openDownloadFolder() {
            if (window.parent && window.parent.openModuleWindow) {
                // Changed from '/ftp/VideoDownloads' to '/VideoDownloads'
                window.parent.openModuleWindow('file-manager', { path: '/VideoDownloads' });
            } else {
                alert('Cannot open File Manager');
            }
        }
    </script>
</body>

</html>