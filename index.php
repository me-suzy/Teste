<?php
// Simple Dreamweaver-like HTML/PHP editor with code + design view
// NOTE: Set your project root folder here:
$ROOT = 'e:/Carte/BB/17 - Site Leadership/'; // schimbă daca vrei alt folder de lucru
mb_internal_encoding('UTF-8');

function norm_path($p) {
    $p = str_replace(["\\", ".."], ["/", ""], $p);
    return ltrim($p, "/");
}

function resolve_path($p, $ROOT) {
    $p = str_replace("\\", "/", $p);
    if (preg_match('#^[a-zA-Z]:/#', $p) || strpos($p, '/') === 0) {
        return $p;
    }
    return rtrim($ROOT, '/') . '/' . ltrim($p, '/');
}

// --- Asset proxy: serve any file from disk (CSS, JS, images, etc.) ---
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
if (strpos($pathInfo, '/asset/') === 0) {
    $assetPath = substr($pathInfo, 7);
    $assetPath = str_replace("\\", "/", $assetPath);
    $assetPath = str_replace("../", "", $assetPath);
    if (file_exists($assetPath) && is_file($assetPath)) {
        $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        $mimes = [
            'css'=>'text/css','js'=>'application/javascript',
            'html'=>'text/html','htm'=>'text/html',
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
            'gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp',
            'ico'=>'image/x-icon','bmp'=>'image/bmp',
            'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf',
            'eot'=>'application/vnd.ms-fontobject',
            'json'=>'application/json','xml'=>'text/xml',
            'mp4'=>'video/mp4','webm'=>'video/webm',
            'pdf'=>'application/pdf',
        ];
        $ct = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
        header('Content-Type: '.$ct);
        header('Cache-Control: public, max-age=3600');
        readfile($assetPath);
    } else {
        http_response_code(404);
        echo "Not found";
    }
    exit;
}

// --- API: preview (HTML) ---
if (isset($_GET['action']) && $_GET['action'] === 'preview') {
    $file = isset($_GET['file']) ? $_GET['file'] : '';
    $full = resolve_path($file, $ROOT);
    if (!file_exists($full)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Fisierul nu exista.";
        exit;
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        chdir(dirname($full));
        include $full;
    } else {
        $dir = str_replace("\\", "/", dirname($full));
        $baseUrl = '/htmleditor/index.php/asset/' . $dir . '/';
        $html = file_get_contents($full);
        $baseTag = '<base href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '">';
        if (preg_match('/<head(\s|>)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $pos) . "\n" . $baseTag . "\n" . substr($html, $pos);
        } else {
            $html = $baseTag . "\n" . $html;
        }
        // Pentru panoul de design afisam strict continutul dintre <body> si </body>;
        // tot ce este in afara acestui perimetru nu va mai fi vizibil.
        if (preg_match('/<head\b[^>]*>[\s\S]*?<\/head>/i', $html, $mh)) {
            $headHtml = $mh[0];
        } else {
            $headHtml = '';
        }
        if (preg_match('/<body\b[^>]*>[\s\S]*?<\/body>/i', $html, $mb)) {
            $bodyHtml = $mb[0];
            $html = "<!DOCTYPE html>\n<html>\n" . $headHtml . "\n" . $bodyHtml . "\n</html>";
        }
        $ct = ($ext === 'css') ? 'text/css' : 'text/html';
        header("Content-Type: {$ct}; charset=utf-8");
        echo $html;
    }
    exit;
}

// --- API JSON (list/load/save) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'list') {
        $root = rtrim($ROOT, "/");
        $subdir = isset($_GET['dir']) ? $_GET['dir'] : '';
        $subdir = str_replace(["\\",".."], ["/",""], $subdir);
        $subdir = trim($subdir, '/');
        $scanPath = $subdir ? ($root . '/' . $subdir) : $root;
        $out = [];
        if (is_dir($scanPath)) {
            $items = scandir($scanPath);
            foreach ($items as $f) {
                if ($f === '.' || $f === '..') continue;
                $full = $scanPath . '/' . $f;
                $relPath = $subdir ? ($subdir . '/' . $f) : $f;
                if (is_dir($full)) {
                    $out[] = ['type' => 'dir', 'path' => $relPath, 'name' => $f];
                } else {
                    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                    if (in_array($ext, ['html', 'htm', 'css', 'js', 'php'])) {
                        $out[] = ['type' => 'file', 'path' => $relPath, 'name' => $f, 'ext' => $ext];
                    }
                }
            }
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Load file - supports both relative (to ROOT) and absolute paths
    if ($action === 'load') {
        $file = isset($_GET['file']) ? $_GET['file'] : '';
        $full = resolve_path($file, $ROOT);
        if (!file_exists($full)) {
            echo json_encode(['ok' => false, 'error' => 'Fisierul nu exista: ' . $full]);
            exit;
        }
        $txt = file_get_contents($full);
        echo json_encode(['ok' => true, 'file' => $full, 'content' => $txt]);
        exit;
    }

    // Save file - supports both relative and absolute paths
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = isset($_POST['file']) ? $_POST['file'] : '';
        $full = resolve_path($file, $ROOT);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            echo json_encode(['ok' => false, 'error' => 'Directorul nu exista: ' . $dir]);
            exit;
        }
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        file_put_contents($full, $content);
        echo json_encode(['ok' => true, 'file' => $full]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Actiune necunoscuta']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Mini Dreamweaver - Editor HTML/PHP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Segoe UI,system-ui,sans-serif;background:#1e1f26;color:#eaeaea;height:100vh;display:flex;flex-direction:column;font-size:17px}
        .topbar{background:#252633;padding:10px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #333;font-size:16px}
        .brand{font-weight:700;color:#fff;font-size:18px}
        .topbar input[type=text]{background:#15161d;border:1px solid #444;border-radius:4px;padding:5px 8px;color:#eee;font-size:16px;min-width:280px}
        .btn{padding:7px 14px;border:none;border-radius:4px;font-size:15px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
        .btn-primary{background:#3b82f6;color:#fff}
        .btn-ghost{background:#2b2c3a;color:#ccc}
        .btn-primary:hover{background:#2563eb}
        .btn-ghost:hover{background:#3b3d4d}
        .main{flex:1;display:flex;min-height:0}
        .sidebar{width:280px;background:#181924;border-right:1px solid #333;overflow:auto;padding:10px}
        .sidebar h3{font-size:17px;margin-bottom:8px;color:#9ca3af;text-transform:uppercase;letter-spacing:.6px}
        .file{padding:6px 10px;font-size:17px;cursor:pointer;border-radius:3px;display:flex;justify-content:space-between;align-items:center}
        .file:hover{background:#2a2b3a}
        .file.active{background:#3b82f6;color:#fff}
        .file .path{opacity:.6;font-size:14px}
        .dir{padding:4px 6px;font-size:16px;color:#9ca3af;margin-top:4px}
        .dir span{opacity:.7}
        .center{flex:1;display:flex;flex-direction:column;min-width:0}
        .tabs{background:#181924;border-bottom:1px solid #333;padding:8px 12px;font-size:16px;color:#9ca3af;display:flex;justify-content:space-between;align-items:center}
        .split{flex:1;display:flex;min-height:0;position:relative}
        .editor-pane{flex:1;border-right:1px solid #333;display:flex;flex-direction:column;min-width:0;overflow:hidden}
        .editor-header{padding:8px 12px;font-size:16px;background:#20212c;border-bottom:1px solid #333;display:flex;justify-content:space-between;align-items:center}
        .preview-pane{flex:1;display:flex;flex-direction:column;min-width:0;overflow:hidden}
        .preview-header{padding:8px 12px;font-size:16px;background:#20212c;border-bottom:1px solid #333;display:flex;justify-content:space-between;align-items:center}
        iframe{flex:1;border:none;background:#fff}
        .viewmode-tabs{display:inline-flex;border:1px solid #4b5563;border-radius:6px;overflow:hidden;margin-bottom:6px;font-size:14px}
        .viewmode-tabs button{background:#111827;border:none;color:#e5e7eb;padding:4px 10px;cursor:pointer}
        .viewmode-tabs button.active{background:#e5e7eb;color:#111827;font-weight:600}
        .viewmode-tabs button+button{border-left:1px solid #4b5563}
        .split.mode-code .preview-pane{display:none}
        .split.mode-code .editor-pane{flex:1;border-right:none}
        .split.mode-design .editor-pane{display:none}
        .split.mode-design .preview-pane{flex:1}
        .split.mode-split .editor-pane{display:flex}
        .split.mode-split .preview-pane{display:flex}
        .split .divider{width:6px;cursor:col-resize;background:#111827;border-left:1px solid #333;border-right:1px solid #333;z-index:10;position:relative}
        .split-overlay{position:absolute;inset:0;cursor:col-resize;z-index:20;display:none}
        .status{font-size:15px;color:#9ca3af}
        .cm-editor{height:100%}
        .cm-s-material-darker{background:#111827;color:#e5e7eb;font-size:17px}
        .CodeMirror-selected{background:rgba(250,204,21,0.25)!important}
        .CodeMirror-focused .CodeMirror-selected{background:rgba(250,204,21,0.35)!important}
        .properties-panel{background:#20212c;border-top:1px solid #333;padding:10px 16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;font-size:15px;min-height:46px}
        .properties-panel label{color:#9ca3af;margin-right:2px}
        .properties-panel select,.properties-panel input[type="number"]{background:#15161d;border:1px solid #444;border-radius:4px;padding:5px 10px;color:#eee;font-size:14px}
        .properties-panel input[type="color"]{width:30px;height:26px;padding:2px;cursor:pointer;border-radius:4px;border:1px solid #444}
        .properties-panel .prop-btn{padding:5px 12px;min-width:34px;font-weight:700;height:30px}

        /* Overlay start dialog */
        .overlay{position:fixed;inset:0;background:rgba(15,23,42,.92);display:flex;align-items:center;justify-content:center;z-index:2000}
        .overlay-card{width:700px;max-width:92%;background:#020617;border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.6);border:1px solid #1f2937;padding:34px 34px 26px;color:#e5e7eb}
        .overlay-title{font-size:26px;font-weight:700;margin-bottom:12px}
        .overlay-sub{font-size:18px;color:#9ca3af;margin-bottom:22px}
        .drop-zone{border:2px dashed #4b5563;border-radius:14px;padding:48px 28px;text-align:center;background:rgba(15,23,42,.8);cursor:pointer;transition:.15s all;font-size:18px}
        .drop-zone.over{border-color:#3b82f6;background:rgba(37,99,235,.12)}
        .drop-zone i{font-size:50px;color:#3b82f6;margin-bottom:14px}
        .drop-zone .drop-label{font-size:20px;font-weight:600;margin-bottom:8px}
        .drop-zone .drop-hint{font-size:16px;color:#9ca3af}
        .drop-main{margin-top:22px;font-size:17px;color:#9ca3af}
        .overlay-actions{display:flex;justify-content:flex-end;gap:12px;margin-top:24px;font-size:17px}
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
    <div class="overlay" id="startOverlay">
        <div class="overlay-card">
            <div class="overlay-title">Deschide un fisier</div>
            <div class="overlay-sub">Scrie calea completa, trage un fisier, sau click pe zona de mai jos.</div>
            <div style="display:flex;gap:8px;margin-bottom:18px">
                <input type="text" id="pathInput" placeholder="Ex: e:/Carte/BB/fisier.html" style="flex:1;background:#15161d;border:1px solid #444;border-radius:6px;padding:10px 12px;color:#eee;font-size:16px">
                <button class="btn btn-primary" onclick="openFromPath()" style="white-space:nowrap">Deschide</button>
            </div>
            <div class="drop-zone" id="dropZone">
                <i class="fas fa-file-code"></i>
                <div class="drop-label">Drag &amp; Drop fisier aici</div>
                <div class="drop-hint">sau click pentru a alege un fisier</div>
                <div id="dropStatus" style="margin-top:12px;font-size:15px;color:#facc15;display:none"></div>
            </div>
            <input type="file" id="filePicker" accept=".html,.htm,.css,.js,.php" style="display:none">
            <div class="drop-main">Poti inchide acest ecran si folosi oricand lista de fisiere din stanga pentru a deschide manual fisierele.</div>
            <div class="overlay-actions">
                <button class="btn btn-ghost" onclick="hideOverlay()">Continua la editor</button>
            </div>
        </div>
    </div>
    <div class="topbar">
        <div class="brand">Mini Dreamweaver</div>
        <span style="font-size:12px;color:#9ca3af">Root:</span>
        <input type="text" value="<?php echo htmlspecialchars($ROOT,ENT_QUOTES,'UTF-8'); ?>" readonly>
        <button class="btn btn-ghost" onclick="refreshList()">Reincarca lista fisiere</button>
        <div style="flex:1"></div>
        <button class="btn btn-ghost" onclick="closeEditor()">Inchide</button>
        <button class="btn btn-primary" onclick="saveFile()">Salveaza (Ctrl+S)</button>
    </div>
    <div class="main">
        <div class="sidebar">
            <h3>Fisiere (HTML / CSS / JS / PHP)</h3>
            <div id="fileList"></div>
        </div>
        <div class="center">
            <div style="display:flex;align-items:center;justify-content:space-between">
                <div class="viewmode-tabs">
                    <button type="button" id="btnViewCode" onclick="setViewMode('code')">Code</button>
                    <button type="button" id="btnViewSplit" onclick="setViewMode('split')">Split</button>
                    <button type="button" id="btnViewDesign" onclick="setViewMode('design')">Design</button>
                </div>
                <div class="tabs">
                    <div id="tabFile">Niciun fisier deschis</div>
                    <div class="status" id="status">Pregatit</div>
                </div>
            </div>
            <div class="split mode-split" id="splitContainer">
                <div class="editor-pane">
                    <div class="editor-header">
                        <div>Cod (UTF-8)</div>
                        <div style="font-size:11px;color:#9ca3af">Editare HTML / CSS / JS / PHP</div>
                    </div>
                    <textarea id="code" name="code"></textarea>
                </div>
                <div class="divider" id="splitDivider"></div>
                <div class="preview-pane">
                    <div class="preview-header">
                        <div>Design / Preview</div>
                        <button class="btn btn-ghost" onclick="updatePreview()">Reincarca preview</button>
                    </div>
                    <iframe id="preview"></iframe>
                </div>
            </div>
            <div id="propertiesPanel" class="properties-panel">
                <span style="color:#9ca3af;margin-right:8px">Proprietati font:</span>
                <label>Font:</label><select id="propFont"><option value="">(mostenit)</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Times New Roman">Times New Roman</option><option value="Verdana">Verdana</option><option value="Source Sans Pro, sans-serif">Source Sans Pro</option></select>
                <label>Clasa:</label><select id="propClass"><option value="">(fara)</option></select>
                <label>Marime:</label><select id="propSize"><option value="">(mostenit)</option><option value="12">12px</option><option value="14">14px</option><option value="16">16px</option><option value="18">18px</option><option value="20">20px</option><option value="24">24px</option></select>
                <button type="button" class="btn btn-ghost prop-btn" id="propBold" title="Bold">B</button>
                <button type="button" class="btn btn-ghost prop-btn" id="propItalic" title="Italic">I</button>
                <label>Text:</label><input type="color" id="propColor" value="#000000" title="Culoare text">
                <label>Fundal:</label><input type="color" id="propBg" value="#ffffff" title="Culoare fundal">
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
    <script>
        let editor;
        let currentFile = null;
        let currentDir = '';
        let isSyncFromCode = false;
        let isSyncFromDesign = false;
        let previewDebounceTimer = null;
        let designInputDebounceTimer = null;
        let cssClasses = [];
        let isSelectionFromDesign = false;
        let isSelectionFromCode = false;
        let isDirty = false;
        let viewMode = 'split';
        let skipPreviewUpdateUntil = 0;

        function toast(msg) {
            const st = document.getElementById('status');
            st.textContent = msg;
            setTimeout(()=>{ st.textContent='Pregatit'; }, 4000);
        }

        function shortName(path) {
            if (!path) return '';
            return path.replace(/\\/g,'/').split('/').pop();
        }

        function initEditor() {
            editor = CodeMirror.fromTextArea(document.getElementById('code'), {
                mode: 'application/x-httpd-php',
                lineNumbers: true,
                theme: 'material-darker',
                lineWrapping: true,
                indentUnit: 4,
                indentWithTabs: false,
                tabSize: 4
            });
            window.editor = editor;
            editor.setSize('100%', '100%');
            editor.on('change', ()=> {
                refreshClassListFromCode();
                isDirty = true;
                if (currentFile) {
                    document.getElementById('tabFile').textContent = shortName(currentFile) + ' *';
                }
                if (isSyncFromDesign) return;
                if (Date.now() < skipPreviewUpdateUntil) return;
                clearTimeout(previewDebounceTimer);
                previewDebounceTimer = setTimeout(()=> {
                    isSyncFromCode = true;
                    updatePreview();
                    setTimeout(()=> { isSyncFromCode = false; }, 500);
                }, 500);
            });

            editor.on('cursorActivity', ()=> {
                if (isSelectionFromDesign) return;
                syncSelectionToDesignFromCode();
            });

            window.addEventListener('keydown', e => {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    saveFile();
                } else if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) {
                    if ((viewMode === 'design' || viewMode === 'split') || (editor && !editor.hasFocus())) {
                        e.preventDefault();
                        if (editor) doUndoFromDesign();
                    }
                } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y' || (e.shiftKey && (e.key === 'z' || e.key === 'Z')))) {
                    if ((viewMode === 'design' || viewMode === 'split') || (editor && !editor.hasFocus())) {
                        e.preventDefault();
                        if (editor) doRedoFromDesign();
                    }
                }
            }, true);
            setViewMode('split');
            initSplitter();
        }

        function setViewMode(mode){
            viewMode = mode;
            const split = document.getElementById('splitContainer');
            if (!split) return;
            // resetam latimile custom cand schimbam modul
            const leftPane = split.querySelector('.editor-pane');
            const rightPane = split.querySelector('.preview-pane');
            if (leftPane && rightPane){
                leftPane.style.flex = '';
                rightPane.style.flex = '';
            }
            split.classList.remove('mode-code','mode-split','mode-design');
            if (mode === 'code') split.classList.add('mode-code');
            else if (mode === 'design') split.classList.add('mode-design');
            else split.classList.add('mode-split');
            const btnCode = document.getElementById('btnViewCode');
            const btnSplit = document.getElementById('btnViewSplit');
            const btnDesign = document.getElementById('btnViewDesign');
            if (btnCode && btnSplit && btnDesign){
                btnCode.classList.toggle('active', mode==='code');
                btnSplit.classList.toggle('active', mode==='split');
                btnDesign.classList.toggle('active', mode==='design');
            }
        }

        function initSplitter(){
            const divider = document.getElementById('splitDivider');
            const split = document.getElementById('splitContainer');
            if (!divider || !split) return;
            let dragging = false;
            let startX = 0;
            let startLeftWidth = 0;
            const leftPane = split.querySelector('.editor-pane');
            const rightPane = split.querySelector('.preview-pane');
            let overlay = split.querySelector('.split-overlay');
            if (!overlay){
                overlay = document.createElement('div');
                overlay.className = 'split-overlay';
                split.appendChild(overlay);
            }
            divider.addEventListener('mousedown', e=>{
                if (viewMode !== 'split') return;
                dragging = true;
                startX = e.clientX;
                startLeftWidth = leftPane.getBoundingClientRect().width;
                document.body.style.cursor = 'col-resize';
                overlay.style.display = 'block';
                e.preventDefault();
            });
            overlay.addEventListener('mousemove', e=>{
                if (!dragging) return;
                const dx = e.clientX - startX;
                const totalWidth = split.getBoundingClientRect().width;
                let newLeft = Math.max(150, Math.min(totalWidth-150, startLeftWidth + dx));
                const percent = (newLeft / totalWidth) * 100;
                leftPane.style.flex = '0 0 ' + percent + '%';
                rightPane.style.flex = '1 0 auto';
            });
            const stopDrag = ()=>{
                if (!dragging) return;
                dragging = false;
                document.body.style.cursor = '';
                overlay.style.display = 'none';
            };
            overlay.addEventListener('mouseup', stopDrag);
            window.addEventListener('mouseup', stopDrag);
        }

        function refreshClassListFromCode(){
            if (!editor) return;
            const code = editor.getValue();
            const re = /class\s*=\s*["']([^"']+)["']/gi;
            const set = new Set();
            let m;
            while((m = re.exec(code)) !== null){
                const parts = m[1].split(/\s+/);
                parts.forEach(c=>{
                    if(c) set.add(c);
                });
            }
            cssClasses = Array.from(set).sort((a,b)=>a.localeCompare(b,'ro'));
            const sel = document.getElementById('propClass');
            if(!sel) return;
            sel.innerHTML = '<option value=\"\">(fara)</option>';
            cssClasses.forEach(c=>{
                const opt=document.createElement('option');
                opt.value=c;
                opt.textContent=c;
                sel.appendChild(opt);
            });
        }

        async function refreshList(dir) {
            if(dir===undefined) dir=currentDir;
            currentDir=dir;
            const cont=document.getElementById('fileList');
            cont.textContent='Se incarca...';
            try {
                const url='?action=list'+(dir?'&dir='+encodeURIComponent(dir):'');
                const res=await fetch(url);
                const data=await res.json();
                renderList(data, dir);
            } catch (e) {
                cont.textContent='Eroare la listare';
            }
        }

        function renderList(items, dir) {
            const cont=document.getElementById('fileList');
            cont.innerHTML='';
            if(dir){
                const back=document.createElement('div');
                back.className='dir';
                back.style.cursor='pointer';
                back.style.color='#60a5fa';
                back.innerHTML='<span>&#8592; Inapoi</span>';
                const parent=dir.includes('/')?dir.split('/').slice(0,-1).join('/'):'';
                back.onclick=()=>refreshList(parent);
                cont.appendChild(back);
            }
            const dirLabel=document.createElement('div');
            dirLabel.className='dir';
            dirLabel.innerHTML='<span>'+(dir||'(root)')+'</span>';
            cont.appendChild(dirLabel);

            const sorted=[...items].sort((a,b)=>{
                if(a.type!==b.type) return a.type==='dir'?-1:1;
                return a.name.localeCompare(b.name);
            });
            sorted.forEach(it=>{
                if(it.type==='dir'){
                    const el=document.createElement('div');
                    el.className='file';
                    el.style.color='#60a5fa';
                    el.innerHTML='<span>&#128193; '+it.name+'</span>';
                    el.onclick=()=>refreshList(it.path);
                    cont.appendChild(el);
                } else {
                    const el=document.createElement('div');
                    el.className='file';
                    el.dataset.path=it.path;
                    el.innerHTML='<span>'+it.name+'</span><span class="path">'+it.ext+'</span>';
                    el.onclick=()=>openFile(it.path,el);
                    cont.appendChild(el);
                }
            });
        }

        async function openFile(path, el) {
            try {
                toast('Se deschide...');
                const res = await fetch('?action=load&file=' + encodeURIComponent(path));
                const data = await res.json();
                if (!data.ok) { toast('Eroare: ' + data.error); return; }
                currentFile = data.file;
                editor.setValue(data.content);
                editor.getDoc().clearHistory();
                document.getElementById('tabFile').textContent = shortName(currentFile);
                document.querySelectorAll('.file').forEach(f=>f.classList.remove('active'));
                if (el) el.classList.add('active');
                updatePreview();
                toast('Fisier incarcat: ' + currentFile);
                isDirty = false;
            } catch (e) {
                toast('Eroare la deschidere: ' + e.message);
            }
        }

        async function saveFile() {
            if (!currentFile) {
                toast('Acest fisier este nou (deschis prin Drag & Drop) si nu are o cale cunoscuta pe disc. Pentru a salva direct in locatia originala, deschide fisierul prin campul de cale sau din lista din stanga.');
                return;
            }
            try {
                const body = new URLSearchParams();
                body.append('file', currentFile);
                body.append('content', editor.getValue());
                const res = await fetch('?action=save', { method:'POST', body });
                const data = await res.json();
                if (!data.ok) { toast('Eroare la salvare: ' + data.error); return; }
                document.getElementById('tabFile').textContent = shortName(currentFile);
                isDirty = false;
                toast('Salvat: ' + currentFile);
                updatePreview();
            } catch (e) {
                toast('Eroare la salvare');
            }
        }

        function isCurrentFileHtml() {
            if (!currentFile) return true;
            const n = currentFile.toLowerCase();
            return n.endsWith('.html') || n.endsWith('.htm');
        }

        function syncFromDesign() {
            if (isSyncFromCode) return;
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || !editor) return;
            const full = editor.getValue();
            const bodyRe = /<body([^>]*)>[\s\S]*<\/body>/i;
            const bodyMatch = bodyRe.exec(full);
            if (!bodyMatch) return;
            const newBody = '<body' + bodyMatch[1] + '>' + doc.body.innerHTML + '</body>';
            isSyncFromDesign = true;
            const from = editor.posFromIndex(bodyMatch.index);
            const to = editor.posFromIndex(bodyMatch.index + bodyMatch[0].length);
            editor.replaceRange(newBody, from, to);
            isSyncFromDesign = false;
            isDirty = true;
            syncSelectionToCodeFromDesign();
        }

        function makeDesignEditable() {
            const iframe = document.getElementById('preview');
            const doc = iframe.contentDocument;
            if (!doc || !doc.body || !isCurrentFileHtml()) return;
            doc.body.contentEditable = 'true';
            doc.body.addEventListener('input', ()=> {
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 120);
            });
            doc.body.addEventListener('keyup', ()=> {
                clearTimeout(designInputDebounceTimer);
                designInputDebounceTimer = setTimeout(syncFromDesign, 120);
            });
            doc.addEventListener('selectionchange', updatePropertiesPanelFromSelection);
            doc.addEventListener('mouseup', updatePropertiesPanelFromSelection);
            doc.addEventListener('keyup', updatePropertiesPanelFromSelection);
            doc.addEventListener('keydown', e=> {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.parent && typeof window.parent.saveFile === 'function') {
                        window.parent.saveFile();
                    }
                } else if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z') && !e.shiftKey) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.parent && typeof window.parent.doUndoFromDesign === 'function') {
                        window.parent.doUndoFromDesign();
                    }
                } else if ((e.ctrlKey || e.metaKey) &&
                           (e.key === 'y' || e.key === 'Y' ||
                            (e.shiftKey && (e.key === 'z' || e.key === 'Z')))) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (window.parent && typeof window.parent.doRedoFromDesign === 'function') {
                        window.parent.doRedoFromDesign();
                    }
                }
            }, true);
        }

        function cancelPreviewUpdateForUndoRedo() {
            clearTimeout(previewDebounceTimer);
            previewDebounceTimer = null;
            skipPreviewUpdateUntil = Date.now() + 700;
        }

        function doUndoFromDesign() {
            if (!editor || !isCurrentFileHtml()) return;
            cancelPreviewUpdateForUndoRedo();
            const iframe = document.getElementById('preview');
            const win = iframe && iframe.contentWindow;
            const savedIframe = win ? { x: win.scrollX || 0, y: win.scrollY || 0 } : null;
            const si = editor.getScrollInfo();
            const savedCode = { left: si.left, top: si.top };
            editor.undo();
            applyUndoRedoToDesign(savedCode, savedIframe);
            refocusDesignAfterUndoRedo(iframe);
        }

        function doRedoFromDesign() {
            if (!editor || !isCurrentFileHtml()) return;
            cancelPreviewUpdateForUndoRedo();
            const iframe = document.getElementById('preview');
            const win = iframe && iframe.contentWindow;
            const savedIframe = win ? { x: win.scrollX || 0, y: win.scrollY || 0 } : null;
            const si = editor.getScrollInfo();
            const savedCode = { left: si.left, top: si.top };
            editor.redo();
            applyUndoRedoToDesign(savedCode, savedIframe);
            refocusDesignAfterUndoRedo(iframe);
        }

        function refocusDesignAfterUndoRedo(iframe) {
            setTimeout(function() {
                try {
                    if (editor && editor.getWrapperElement) editor.getWrapperElement().blur();
                    if (iframe && iframe.contentWindow) iframe.contentWindow.focus();
                } catch (e) {}
            }, 0);
        }

        function applyUndoRedoToDesign(savedCodeScroll, savedIframeScroll) {
            const iframe = document.getElementById('preview');
            const doc = iframe && iframe.contentDocument;
            if (!doc || !doc.body || !editor || !isCurrentFileHtml()) return;
            const win = iframe.contentWindow;
            const scrollX = savedIframeScroll ? savedIframeScroll.x : (win.scrollX || 0);
            const scrollY = savedIframeScroll ? savedIframeScroll.y : (win.scrollY || 0);
            const full = editor.getValue();
            const bodyRe = /<body\b[^>]*>([\s\S]*?)<\/body>/i;
            const m = bodyRe.exec(full);
            if (!m) return;
            isSyncFromCode = true;
            doc.body.innerHTML = m[1];
            win.scrollTo(scrollX, scrollY);
            if (savedCodeScroll) {
                editor.scrollTo(savedCodeScroll.left, savedCodeScroll.top);
            }
            isSyncFromCode = false;
        }

        function updatePreview() {
            const iframe = document.getElementById('preview');
            if (currentFile) {
                iframe.onload = ()=> {
                    if (isCurrentFileHtml()) makeDesignEditable();
                };
                iframe.src = '?action=preview&file=' + encodeURIComponent(currentFile) + '&t=' + Date.now();
            } else if (editor) {
                let content = editor.getValue();
                // Pentru fisiere fara cale (drag & drop / noi): afisam in design doar <body>...</body>
                // dar pastram <head> pentru stiluri
                const lower = content.toLowerCase();
                const headMatch = /<head\b[^>]*>[\s\S]*?<\/head>/i.exec(content);
                const bodyMatch = /<body\b[^>]*>[\s\S]*?<\/body>/i.exec(content);
                if (bodyMatch) {
                    const headHtml = headMatch ? headMatch[0] : '';
                    content = '<!DOCTYPE html><html>' + headHtml + bodyMatch[0] + '</html>';
                }
                const blob = new Blob([content], {type:'text/html;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                iframe.onload = ()=> {
                    makeDesignEditable();
                    URL.revokeObjectURL(url);
                };
                iframe.src = url;
            }
        }

        function hideOverlay(){
            const ov=document.getElementById('startOverlay');
            if(ov)ov.style.display='none';
        }

        function showOverlay(){
            const ov=document.getElementById('startOverlay');
            if(ov)ov.style.display='flex';
        }

        function getDesignSelection() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return null;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            let node = sel.anchorNode;
            if (!node) return null;
            if (node.nodeType === Node.TEXT_NODE) node = node.parentElement;
            if (!node || !node.closest) return node;
            return node.closest('p, span, div, td, th, li, h1, h2, h3, h4, h5, h6, a, strong, em');
        }

        function getDesignSelectionRange() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return null;
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return null;
            const range = sel.getRangeAt(0);
            return { win, doc, sel, range };
        }

        function getDesignSelectionText() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return '';
            const sel = win.getSelection();
            if (!sel || sel.rangeCount === 0) return '';
            let text = sel.toString().trim();
            if (text) return text;
            const node = sel.anchorNode && sel.anchorNode.nodeType === Node.TEXT_NODE ? sel.anchorNode : null;
            if (!node) return '';
            const value = node.nodeValue || '';
            const offset = sel.anchorOffset || 0;
            const left = value.slice(0, offset).split(/\\s+/).pop() || '';
            const right = value.slice(offset).split(/\\s+/)[0] || '';
            text = (left + right).trim();
            return text;
        }

        function syncSelectionToCodeFromDesign() {
            if (!editor) return;
            const text = getDesignSelectionText();
            if (!text) return;
            const src = editor.getValue();
            const lower = src.toLowerCase();
            const bodyRe = /<body[^>]*>[\s\S]*<\/body>/i;
            const bodyMatch = bodyRe.exec(lower);
            if (!bodyMatch) return;
            const bodyLower = bodyMatch[0];
            const needle = text.toLowerCase();
            const localIdx = bodyLower.indexOf(needle);
            if (localIdx === -1) return;
            const globalIdx = bodyMatch.index + localIdx;
            const from = editor.posFromIndex(globalIdx);
            const to = editor.posFromIndex(globalIdx + text.length);
            isSelectionFromDesign = true;
            editor.setSelection(from, to);
            editor.scrollIntoView({from, to}, 100);
            isSelectionFromDesign = false;
        }

        function syncSelectionToDesignFromCode() {
            const iframe = document.getElementById('preview');
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc || !doc.body || !editor) return;
            const selText = (editor.getSelection() || editor.getTokenAt(editor.getCursor()).string || '').trim();
            if (!selText) return;

            // Verificam daca pozitia din cod este in interiorul <body>...</body>
            const src = editor.getValue();
            const lower = src.toLowerCase();
            const bodyRe = /<body[^>]*>[\s\S]*<\/body>/i;
            const bodyMatch = bodyRe.exec(lower);
            if (!bodyMatch) return;
            const bodyStart = bodyMatch.index;
            const bodyEnd = bodyMatch.index + bodyMatch[0].length;
            const curIndex = editor.indexFromPos(editor.getCursor());
            if (curIndex < bodyStart || curIndex >= bodyEnd) {
                // Cursorul este in afara perimetrului <body>, nu sincronizam catre design
                return;
            }

            const target = selText.toLowerCase();
            const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    return node.nodeValue.toLowerCase().includes(target) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP;
                }
            });
            let node = walker.nextNode();
            if (!node) return;
            const text = node.nodeValue;
            const start = text.toLowerCase().indexOf(target);
            if (start === -1) return;
            const range = doc.createRange();
            range.setStart(node, start);
            range.setEnd(node, start + selText.length);
            const sel = win.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            if (node.parentElement && node.parentElement.scrollIntoView) {
                node.parentElement.scrollIntoView({block:'center'});
            }
        }

        function updatePropertiesPanelFromSelection() {
            const el = getDesignSelection();
            const fontSel = document.getElementById('propFont');
            const sizeSel = document.getElementById('propSize');
            const boldBtn = document.getElementById('propBold');
            const italicBtn = document.getElementById('propItalic');
            const colorInp = document.getElementById('propColor');
            const bgInp = document.getElementById('propBg');
            const classSel = document.getElementById('propClass');
            if (!el) return;
            const st = el.style;
            const computed = el.ownerDocument.defaultView.getComputedStyle(el);
            const fontVal = (st.fontFamily || computed.fontFamily || '').split(',')[0].replace(/['"]/g,'').trim();
            const opt = [].find.call(fontSel.options, o=>o.value===fontVal || (o.value && o.value.indexOf(fontVal)===0));
            fontSel.value = opt ? opt.value : '';
            const px = computed.fontSize ? parseFloat(computed.fontSize) : 0;
            sizeSel.value = px ? String(Math.round(px)) : '';
            boldBtn.style.background = (computed.fontWeight === '700' || computed.fontWeight === 'bold') ? 'rgba(59,130,246,0.3)' : '';
            italicBtn.style.background = computed.fontStyle === 'italic' ? 'rgba(59,130,246,0.3)' : '';
            colorInp.value = rgbToHex(computed.color) || '#000000';
            bgInp.value = rgbToHex(computed.backgroundColor) || '#ffffff';
            if (classSel) {
                const cls = (el.className || '').trim().split(/\s+/)[0] || '';
                classSel.value = cssClasses.includes(cls) ? cls : '';
            }
            if (!isSelectionFromCode) {
                syncSelectionToCodeFromDesign();
            }
        }

        function rgbToHex(rgb) {
            if (!rgb || rgb === 'rgba(0, 0, 0, 0)' || rgb === 'transparent') return '#ffffff';
            const m = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
            if (!m) return null;
            return '#' + [1,2,3].map(i=>('0'+parseInt(m[i],10).toString(16)).slice(-2)).join('');
        }

        function applyFontProperty(prop, value) {
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                const span = doc.createElement('span');
                const frag = range.extractContents();
                span.appendChild(frag);
                if (prop === 'fontFamily') span.style.fontFamily = value || null;
                else if (prop === 'fontSize') span.style.fontSize = value ? (value + 'px') : null;
                else if (prop === 'color') span.style.color = value || null;
                else if (prop === 'backgroundColor') span.style.backgroundColor = value || null;
                range.insertNode(span);
                sel.removeAllRanges();
                sel.selectAllChildren(span);
            } else {
                const el = getDesignSelection();
                if (!el) return;
                if (prop === 'fontFamily') el.style.fontFamily = value || null;
                else if (prop === 'fontSize') el.style.fontSize = value ? (value + 'px') : null;
                else if (prop === 'color') el.style.color = value || null;
                else if (prop === 'backgroundColor') el.style.backgroundColor = value || null;
            }
            syncFromDesign();
        }

        function toggleInlineFormat(kind){
            const tagName = kind === 'bold' ? 'strong' : 'em';
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                let node = range.commonAncestorContainer;
                if (node.nodeType === 3) node = node.parentElement;
                let fmt = node && node.closest ? node.closest(tagName) : null;
                if (fmt && fmt.tagName.toLowerCase() === tagName) {
                    const parent = fmt.parentNode;
                    while (fmt.firstChild) parent.insertBefore(fmt.firstChild, fmt);
                    parent.removeChild(fmt);
                    sel.removeAllRanges();
                    const newRange = doc.createRange();
                    newRange.selectNodeContents(parent);
                    sel.addRange(newRange);
                } else {
                    const wrapper = doc.createElement(tagName);
                    try {
                        range.surroundContents(wrapper);
                    } catch(e){
                        const frag = range.extractContents();
                        wrapper.appendChild(frag);
                        range.insertNode(wrapper);
                    }
                    sel.removeAllRanges();
                    const newRange = doc.createRange();
                    newRange.selectNodeContents(wrapper);
                    sel.addRange(newRange);
                }
            } else {
                const el = getDesignSelection();
                if (!el) return;
                const doc = el.ownerDocument;
                const existing = el.querySelector(tagName);
                if (existing) {
                    const parent = existing.parentNode;
                    while (existing.firstChild) parent.insertBefore(existing.firstChild, existing);
                    parent.removeChild(existing);
                } else {
                    const wrapper = doc.createElement(tagName);
                    while (el.firstChild) wrapper.appendChild(el.firstChild);
                    el.appendChild(wrapper);
                }
            }
            syncFromDesign();
        }

        function applyClassProperty(value){
            const info = getDesignSelectionRange();
            if (info && !info.range.collapsed) {
                const { doc, sel, range } = info;
                let node = range.commonAncestorContainer;
                if (node.nodeType === 3) node = node.parentElement;
                let span = node && node.closest ? node.closest('span') : null;
                if (span && value && span.classList.contains(value)) {
                    // toggle off: scoatem span-ul
                    const parent = span.parentNode;
                    while (span.firstChild) parent.insertBefore(span.firstChild, span);
                    parent.removeChild(span);
                    sel.removeAllRanges();
                    const newRange = doc.createRange();
                    newRange.selectNodeContents(parent);
                    sel.addRange(newRange);
                } else {
                    const wrap = doc.createElement('span');
                    const frag = range.extractContents();
                    wrap.appendChild(frag);
                    if (value) wrap.className = value;
                    range.insertNode(wrap);
                    sel.removeAllRanges();
                    const newRange = doc.createRange();
                    newRange.selectNodeContents(wrap);
                    sel.addRange(newRange);
                }
            } else {
                const el = getDesignSelection();
                if (!el) return;
                if (value) {
                    if (el.classList.contains(value)) {
                        el.classList.remove(value);
                    } else {
                        el.className = value;
                    }
                } else {
                    el.removeAttribute('class');
                }
            }
            syncFromDesign();
        }

        async function openFromPath(){
            const inp=document.getElementById('pathInput');
            const p=(inp.value||'').trim();
            if(!p){dropStatus('Scrie calea catre fisier','#ef4444');return;}
            dropStatus('Se deschide...','#60a5fa');
            try{
                const res=await fetch('?action=load&file='+encodeURIComponent(p));
                if(!res.ok){dropStatus('Eroare HTTP '+res.status,'#ef4444');return;}
                const txt=await res.text();
                let data;
                try{data=JSON.parse(txt);}catch(e){dropStatus('Raspuns invalid (nu e JSON)','#ef4444');return;}
                if(!data.ok){dropStatus('Eroare: '+(data.error||'necunoscuta'),'#ef4444');return;}
                currentFile=data.file;
                editor.setValue(data.content||'');
                editor.getDoc().clearHistory();
                document.getElementById('tabFile').textContent=shortName(currentFile);
                document.querySelectorAll('.file').forEach(f=>f.classList.remove('active'));
                updatePreview();
                toast('Fisier incarcat: '+currentFile);
                hideOverlay();
                dropStatus('');
                isDirty = false;
            }catch(e){
                dropStatus('Eroare: '+e.message,'#ef4444');
            }
        }

        function closeEditor(){
            if (!editor) { showOverlay(); return; }
            const hasContent = editor.getValue().trim().length > 0;
            if (isDirty && hasContent) {
                const doSave = confirm('Exista modificari nesalvate. Vrei sa le salvezi inainte de inchidere?');
                if (doSave) {
                    saveFile();
                }
            }
            editor.setValue('');
            currentFile = null;
            document.getElementById('tabFile').textContent = 'Niciun fisier deschis';
            const iframe = document.getElementById('preview');
            if (iframe) iframe.src = 'about:blank';
            document.querySelectorAll('.file').forEach(f=>f.classList.remove('active'));
            isDirty = false;
            showOverlay();
        }

        const ALLOWED_EXT = ['.html','.htm','.css','.js','.php'];
        function hasAllowedExt(filename){
            const n=(filename||'').toLowerCase();
            return ALLOWED_EXT.some(ext=>n.endsWith(ext));
        }

        function dropStatus(msg, color){
            const el=document.getElementById('dropStatus');
            if(!el)return;
            el.style.display=msg?'block':'none';
            el.style.color=color||'#facc15';
            el.textContent=msg||'';
        }

        function handleDropFile(file){
            if(!file){dropStatus('Niciun fisier primit','#ef4444');return;}
            if(!hasAllowedExt(file.name)){dropStatus('Doar fisiere HTML, CSS, JS sau PHP','#ef4444');return;}
            dropStatus('Se citeste fisierul "'+file.name+'"...','#60a5fa');
            const reader=new FileReader();
            reader.onload=function(){
                editor.setValue(reader.result||'');
                editor.getDoc().clearHistory();
                const savePath=prompt(
                    'Fisierul "'+file.name+'" a fost citit.\n\n'+
                    'Scrie calea COMPLETA pe disc pentru a putea salva modificarile.\n'+
                    'Exemplu: e:/Carte/BB/17 - Site Leadership/Principal/ro/'+file.name+'\n\n'+
                    'Sau apasa Cancel pentru doar citire (fara salvare).',
                    file.name
                );
                if(savePath && savePath.trim() && savePath.trim()!==file.name){
                    currentFile=savePath.trim().replace(/\\/g,'/');
                    document.getElementById('tabFile').textContent=shortName(currentFile);
                    toast('Fisier incarcat. Se va salva la: '+currentFile);
                } else {
                    currentFile=null;
                    document.getElementById('tabFile').textContent=file.name+' (doar citire)';
                    toast('Fisier incarcat doar in citire');
                }
                updatePreview();
                document.querySelectorAll('.file').forEach(f=>f.classList.remove('active'));
                hideOverlay();
                dropStatus('');
            };
            reader.onerror=function(){dropStatus('Eroare la citirea fisierului','#ef4444');};
            reader.readAsText(file,'UTF-8');
        }

        window.addEventListener('load', ()=> {
            initEditor();
            refreshList('');
            document.addEventListener('dragover',e=>e.preventDefault(),false);
            document.addEventListener('drop',e=>e.preventDefault(),false);

            document.getElementById('pathInput').addEventListener('keydown',e=>{
                if(e.key==='Enter'){e.preventDefault();openFromPath();}
            });

            const dz=document.getElementById('dropZone');
            const fp=document.getElementById('filePicker');
            if(dz){
                dz.addEventListener('click',()=>{if(fp)fp.click();});
                ['dragenter','dragover'].forEach(evt=>dz.addEventListener(evt,e=>{e.preventDefault();e.stopPropagation();dz.classList.add('over');}));
                dz.addEventListener('dragleave',e=>{e.preventDefault();e.stopPropagation();dz.classList.remove('over');});
                dz.addEventListener('drop',e=>{
                    e.preventDefault();
                    e.stopPropagation();
                    dz.classList.remove('over');
                    const f=e.dataTransfer.files&&e.dataTransfer.files[0];
                    handleDropFile(f);
                });
            }
            if(fp){
                fp.addEventListener('change',()=>{
                    const f=fp.files&&fp.files[0];
                    if(f)handleDropFile(f);
                    fp.value='';
                });
            }

            document.getElementById('propFont').addEventListener('change', ()=> applyFontProperty('fontFamily', document.getElementById('propFont').value));
            document.getElementById('propClass').addEventListener('change', ()=> applyClassProperty(document.getElementById('propClass').value));
            document.getElementById('propSize').addEventListener('change', ()=> applyFontProperty('fontSize', document.getElementById('propSize').value));
            document.getElementById('propBold').addEventListener('click', ()=> {
                toggleInlineFormat('bold');
            });
            document.getElementById('propItalic').addEventListener('click', ()=> {
                toggleInlineFormat('italic');
            });
            document.getElementById('propColor').addEventListener('input', ()=> applyFontProperty('color', document.getElementById('propColor').value));
            document.getElementById('propBg').addEventListener('input', ()=> applyFontProperty('backgroundColor', document.getElementById('propBg').value));
        });
    </script>
</body>
</html>

