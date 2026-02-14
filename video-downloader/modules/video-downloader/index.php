<?php
require_once '../../core/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Downloader</title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('assets/xp/xp-ui.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('assets/xp/xp-controls.css'); ?>">
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
        .input-group input {
            flex: 1;
            padding: 4px;
            border: 1px solid #7F9DB9;
        }
        .btn {
            min-width: 80px;
        }
        #log-area {
            flex: 1;
            background: white;
            border: 1px solid #7F9DB9;
            padding: 8px;
            overflow-y: auto;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .status-bar {
            border-top: 1px solid #999;
            padding-top: 5px;
            color: #666;
        }
        .video-preview {
            display: flex;
            gap: 10px;
            background: #fff;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
            display: none;
        }
        .video-preview img {
            max-width: 120px;
            height: auto;
            border: 1px solid #000;
        }
        .video-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .video-title {
            font-weight: bold;
            font-size: 12px;
        }
        .toolbar {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
        }
        /* New styles for preview-area and log-area */
        .preview-area {
            display: flex;
            gap: 10px;
            background: #fff;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
        }
        .preview-area .video-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .preview-area img {
            max-width: 120px;
            height: auto;
            border: 1px solid #000;
        }
        .preview-area .title {
            font-weight: bold;
            font-size: 12px;
        }
        .log-area {
            flex: 1;
            background: white;
            border: 1px solid #7F9DB9;
            padding: 8px;
            overflow-y: auto;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .form-control {
            padding: 4px;
            border: 1px solid #7F9DB9;
        }
    </style>
</head>
<body>

    <div class="input-group">
        <input type="text" id="video-url" placeholder="Paste YouTube URL here..." class="form-control">
        <button class="btn btn-xp" onclick="checkUrl()">Check</button>
    </div>
    
    <div id="format-selection" style="display:none; margin-top: 10px;">
        <label for="quality-select">Quality:</label>
        <select id="quality-select" class="form-control" style="width: auto; display: inline-block;">
            <option value="">Best (Auto)</option>
        </select>
    </div>

    <div class="preview-area" id="preview-area" style="display:none;">
        <div class="video-info">
            <img id="thumb" src="" alt="Thumbnail">
            <div class="details">
                <div class="title" id="video-title">Title</div>
                <div class="duration" id="video-duration">Duration: 0:00</div>
                <button class="btn btn-xp" onclick="startDownload()" style="margin-top: 10px;">Download Video</button>
            </div>
        </div>
    </div>

    <div class="log-area" id="log-area" style="display:none;"></div>
    
    <div style="margin-top:20px; text-align:right;">
        <button class="btn btn-xp" onclick="openTestWindow()">Test System</button>
        <button class="btn btn-xp" onclick="document.getElementById('log-area').innerHTML=''; document.getElementById('log-area').style.display='none';">Clear Log</button>
    </div>
    
    <div class="status-bar" id="status-bar">Waiting for input...</div>

    <script>
        const DEBUG_URL = 'debug.php';
        const statusEl = document.getElementById('status-bar');
        const logArea = document.getElementById('log-area');

        function log(msg) {
            const div = document.createElement('div');
            div.textContent = msg;
            logArea.appendChild(div);
            logArea.scrollTop = logArea.scrollHeight;
        }

        async function checkUrl() {
            const url = document.getElementById('video-url').value;
            if(!url) return alert('Please enter a URL');

            statusEl.innerText = 'Checking URL...';
            document.getElementById('preview-area').style.display = 'none';
            document.getElementById('format-selection').style.display = 'none';
            logArea.style.display = 'none';
            logArea.innerHTML = '';

            const formData = new FormData();
            formData.append('action', 'formats');
            formData.append('url', url);

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    throw new Error('Invalid JSON: ' + text);
                }

                if(data.error) {
                    statusEl.innerText = 'Error: ' + data.error;
                    log('Error: ' + data.error);
                    if(data.details) log('Details:\n' + data.details);
                    logArea.style.display = 'block';
                } else {
                    document.getElementById('video-title').innerText = data.title;
                    document.getElementById('video-duration').innerText = 'Duration: ' + data.duration;
                    document.getElementById('thumb').src = data.thumbnail;
                    document.getElementById('preview-area').style.display = 'block';
                    statusEl.innerText = 'Ready to download via yt-dlp';
                    
                    // Populate formats
                    const sel = document.getElementById('quality-select');
                    sel.innerHTML = '<option value="">Best (Auto)</option>';
                    if (data.formats && data.formats.length) {
                        data.formats.forEach(f => {
                            const opt = document.createElement('option');
                            opt.value = f.id;
                            opt.textContent = f.label;
                            sel.appendChild(opt);
                        });
                        document.getElementById('format-selection').style.display = 'block';
                    }
                }
            } catch(e) {
                statusEl.innerText = 'Error checking URL';
                log(e.message);
                logArea.style.display = 'block';
            }
        }

        async function startDownload() {
            const url = document.getElementById('video-url').value;
            const formatId = document.getElementById('quality-select').value;
            
            if(!url) return;

            statusEl.innerText = 'Downloading... (this may take a while)';
            log('Starting download...');
            logArea.style.display = 'block';
            
            // Disable button
            document.querySelector('.preview-area .btn-xp').disabled = true;

            const formData = new FormData();
            formData.append('action', 'download');
            formData.append('url', url);
            if(formatId) formData.append('format', formatId);

            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const text = await res.text();
                let data; 
                try { data = JSON.parse(text); } catch(e) { throw new Error(text); }

                if(data.error) {
                    statusEl.innerText = 'Download failed.';
                    log('Error: ' + data.error);
                    if(data.details) log('Details:\n' + data.details);
                    logArea.style.display = 'block';
                } else {
                    log('Download Complete!');
                    log('Saved to: ' + data.filename);
                    statusEl.innerText = 'Done.';
                    
                    // Show "Open in File Manager" button
                    const btnOpen = document.createElement('button');
                    btnOpen.className = 'btn btn-xp';
                    btnOpen.innerText = 'Open in File Manager';
                    btnOpen.style.marginTop = '10px';
                    btnOpen.onclick = () => {
                        if(window.parent && window.parent.openModuleWindow) {
                            window.parent.openModuleWindow('file-manager', { path: 'VideoDownloads' });
                        } else {
                            alert('Cannot open File Manager');
                        }
                    };
                    
                    const infoBox = document.querySelector('.preview-area .video-info .details'); // Target the details div for the button
                    const oldBtn = infoBox.querySelector('.btn-open-fm');
                    if(oldBtn) oldBtn.remove();
                    
                    btnOpen.classList.add('btn-open-fm');
                    infoBox.appendChild(btnOpen);
                }
            } catch(e) {
                statusEl.innerText = 'Error during download';
                log(e.message);
                logArea.style.display = 'block';
            } finally {
                document.querySelector('.preview-area .btn-xp').disabled = false;
            }
        }
        
        async function openTestWindow() {
             log('Running system diagnostics...');
             logArea.style.display = 'block';
             try {
                 const res = await fetch(DEBUG_URL);
                 const text = await res.text();
                 log('--- DIAGNOSTICS ---\n' + text + '\n--- END ---');
             } catch(e) {
                 log('Failed to run diagnostics: ' + e.message);
             }
        }

        // Event listeners are handled via onclick attributes in HTML
    </script>
</body>
</html>