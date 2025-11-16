<?php
// === KONFIGURASI DAN PATHS ===
$documentRoot = $_SERVER['DOCUMENT_ROOT']; 
$scriptDirectory = __DIR__;
$directory = $scriptDirectory . '/';

// === HELPER FUNCTIONS ===

// Fungsi untuk mendapatkan daftar file di direktori
function getFilesInDirectory($directory) {
    if (!is_dir($directory)) return [];
    $files = scandir($directory);
    return array_filter($files, fn($file) => !in_array($file, ['.', '..']));
}

// Fungsi untuk mendapatkan path relatif dari path absolut terhadap DOCUMENT_ROOT
function getRelativePath($absolutePath) {
    global $documentRoot;
    if (strpos($absolutePath, $documentRoot) === 0) {
        $relativePath = '/' . ltrim(str_replace($documentRoot, '', $absolutePath), '/');
        if (is_dir($absolutePath)) {
            return rtrim($relativePath, '/') . '/';
        }
        return $relativePath;
    }
    return $absolutePath;
}

// Fungsi untuk menghapus folder secara rekursif
function rrmdir($dir) {
    if (!is_dir($dir)) return true;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? rrmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}


// === INPUT DARI USER (GET/POST) HANDLER ===

// Menentukan direktori yang diminta
if (isset($_REQUEST['directory'])) {
    $safeDir = rtrim($_REQUEST['directory'], '/') . '/';
    $realUserPath = realpath($documentRoot . '/' . ltrim($safeDir, '/'));

    if ($realUserPath && strpos($realUserPath, $documentRoot) === 0) {
        $directory = $realUserPath . '/';
    }
}

// === POST HANDLERS (AJAX OPERATIONS) ===

// 1. BUAT FOLDER BARU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_folder'])) {
    $folderName = basename($_POST['new_folder']);
    $newPath = $directory . $folderName;
    
    header('Content-Type: application/json');
    if (file_exists($newPath)) {
        echo json_encode(['success' => false, 'message' => "Folder '$folderName' sudah ada."]);
    } elseif (!mkdir($newPath, 0755)) {
        echo json_encode(['success' => false, 'message' => "Gagal membuat folder: '$folderName'. Pastikan izin tulis."]);
    } else {
        echo json_encode(['success' => true, 'message' => "Folder '$folderName' berhasil dibuat."]);
    }
    exit;
}

// 2. UPLOAD FILES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    $errors = [];
    foreach ($_FILES['files']['name'] as $key => $name) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['files']['tmp_name'][$key];
            $destination = $directory . basename($name);
            if (!move_uploaded_file($tmpName, $destination)) {
                $errors[] = "Gagal upload file: $name";
            }
        } else {
            $errors[] = "Error saat upload file: $name (Code: " . $_FILES['files']['error'][$key] . ")";
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => empty($errors), 'message' => implode(', ', $errors) ?: 'Upload berhasil.']);
    exit;
}

// 3. SIMPAN/EDIT FILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_name'], $_POST['content'])) {
    $fileName = basename($_POST['file_name']);
    $content = $_POST['content'];
    $filePath = $directory . $fileName;
    $isNewFile = !file_exists($filePath);
    
    header('Content-Type: application/json');
    if (file_put_contents($filePath, $content) !== false) {
        echo json_encode(['success' => true, 'message' => 'File berhasil disimpan.', 'isNew' => $isNewFile]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan file. Pastikan izin tulis (chmod 777).']);
    }
    exit;
}

// 4. RENAME/PINDAH FILE/FOLDER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_item'], $_POST['new_name'])) {
    $oldName = basename($_POST['rename_item']);
    $newName = basename($_POST['new_name']);
    
    $oldPath = $directory . $oldName;
    $newPath = $directory . $newName;

    header('Content-Type: application/json');
    if (!file_exists($oldPath)) {
        echo json_encode(['success' => false, 'message' => "Item lama tidak ditemukan."]);
    } elseif (file_exists($newPath)) {
        echo json_encode(['success' => false, 'message' => "Nama '$newName' sudah ada."]);
    } elseif (rename($oldPath, $newPath)) {
        echo json_encode(['success' => true, 'message' => "$oldName berhasil diubah menjadi $newName."]);
    } else {
        echo json_encode(['success' => false, 'message' => "Gagal mengubah nama item. Pastikan izin tulis."]);
    }
    exit;
}


// === GET HANDLERS (URL OPERATIONS) ===

// 1. DELETE FILE/FOLDER
if (isset($_GET['delete'])) {
    $delFile = basename($_GET['delete']);
    $delPath = $directory . $delFile;
    
    if (file_exists($delPath)) {
        if (is_file($delPath)) {
            $success = unlink($delPath);
        } elseif (is_dir($delPath)) {
            $success = rrmdir($delPath);
        } else {
            $success = false;
        }

        if ($success) {
            header("Location: ?directory=" . urlencode(getRelativePath($directory)));
            exit;
        }
    }
    http_response_code(400); echo "Gagal menghapus item atau item tidak ditemukan."; exit;
}

// 2. ZIP DOWNLOAD FOLDER
if (isset($_GET['zip_dir'])) {
    $safeDir = rtrim($_GET['zip_dir'], '/');
    $realUserPath = realpath($documentRoot . '/' . ltrim($safeDir, '/'));
    
    if ($realUserPath && strpos($realUserPath, $documentRoot) === 0 && is_dir($realUserPath)) {
        if (!class_exists('ZipArchive')) {
            http_response_code(500); echo "ZipArchive PHP extension tidak tersedia."; exit;
        }
        $zip = new ZipArchive();
        $zipFile = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
        $folderName = basename($realUserPath);
        
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($realUserPath, RecursiveDirectoryIterator::SKIP_DOTS), 
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = $folderName . '/' . substr($filePath, strlen($realUserPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $folderName . '.zip"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            unlink($zipFile);
            exit;
        }
    }
    http_response_code(404); echo "Folder tidak ditemukan atau tidak dapat di-zip."; exit;
}

// 3. DOWNLOAD FILE DARI LIST 
if (isset($_GET['download_file'])) {
    $fileName = basename($_GET['download_file']);
    $filePath = $directory . $fileName;

    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    http_response_code(404); echo "File tidak ditemukan."; exit;
}







// 4. MEMBACA ISI FILE UNTUK EDITOR
$fileContent = ''; $fileName = $_GET['file'] ?? '';
if ($fileName) {
    $safeFile = basename($fileName);
    $filePath = $directory . $safeFile;
    if (file_exists($filePath) && is_file($filePath)) {
        $fileContent = file_get_contents($filePath);
    } else {
        $fileName = '';
    }
}


// === PERSIAPAN DATA UNTUK TAMPILAN ===
$files = getFilesInDirectory($directory);
$relativeDir = getRelativePath($directory);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Editor Pro</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/vs2015.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* Reset & Base */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Consolas', 'Fira Code', 'Cascadia Code', Menlo, monospace;
            min-height: 100vh;
            padding: 1rem;
        }

        /* Editor Container (VS Code Style) - PERTAHANKAN INI */
        .code-editor {
            display: flex;
            background: #1e1e1e;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            font-size: 13px;
            line-height: 1.25rem;
            height: 600px;
        }

        /* Line Numbers - PERTAHANKAN INI */
        #lineNumbers {
            width: 40px;
            padding: 0.5rem 0;
            background: #2a2a2a;
            color: #858585;
            text-align: right;
            user-select: none;
            border-right: 1px solid #3e3e3e;
            overflow: hidden;
            font-size: 11px;
            line-height: 1.25rem;
            flex-shrink: 0;
            white-space: pre;
        }

        #lineNumbers > div {
            height: 1.25rem;
            padding-right: 0.5rem;
        }

        /* Textarea (Code Area) - PERTAHANKAN INI */
        #content {
            flex: 1;
            padding: 0.5rem 1rem;
            background: transparent;
            color: #d4d4d4;
            font: inherit;
            line-height: inherit;
            white-space: pre;
            overflow-wrap: normal;
            overflow-x: auto;
            overflow-y: auto;
            resize: none;
            outline: none;
            border: none;
            caret-color: #569cd6;
        }

        /* Scrollbar */
        #content::-webkit-scrollbar, #lineNumbers::-webkit-scrollbar, .file-list-container::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        #content::-webkit-scrollbar-track, #lineNumbers::-webkit-scrollbar-track, .file-list-container::-webkit-scrollbar-track {
            background: #1e1e1e;
        }
        #content::-webkit-scrollbar-thumb, #lineNumbers::-webkit-scrollbar-thumb, .file-list-container::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 3px;
        }

        .hljs { background: transparent; padding: 0; }
        
        /* New Custom Styles for Layout & Modal */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); display: none; 
            justify-content: center; align-items: center; z-index: 50;
        }
        .modal-content {
            background: #252526; padding: 20px; border-radius: 8px;
            width: 90%; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.5);
        }
        .file-list-container {
            max-height: calc(100vh - 250px); /* Sesuaikan tinggi daftar file */
        }
        .text-dark-text-sec { color: #a0a0a0; }
        .text-dark-text { color: #d4d4d4; }
        .border-dark-border { border-color: #3e3e3e; }
        .bg-dark-bg { background-color: #252526; }
        .text-accent-blue { color: #00bcd4; }
    </style>
</head>
<body onload="hljs.highlightAll()">

<div class="max-w-7xl mx-auto space-y-6">

    <div class="bg-[#252526] p-5 rounded-lg shadow-xl flex justify-between items-center">
        <div>
            
<h1 id="titleReload" 
    class="text-3xl font-extrabold text-[#00bcd4] cursor-pointer hover:text-white transition duration-200">
    PHP Editor Pro
</h1>
<p class="text-sm text-[#a0a0a0]">Simple File Manager & Editor</p>
        </div>
</div>

       
 <div class="text-sm">
            <code class="bg-[#3c3c3c] text-yellow-300 px-3 py-1 rounded">
                <?= htmlspecialchars(rtrim($relativeDir, '/')) ?>
            </code>
</div>
    

<input type="hidden" id="currentFilePath" 
       value="<?= isset($relativeDir, $fileName) ? htmlspecialchars($relativeDir . '/' . $fileName) : '' ?>">


    <div class="lg:flex lg:space-x-6">
        
        <div class="lg:w-2/3 space-y-4 mb-6 lg:mb-0">
            <form id="fileForm" class="space-y-4" onsubmit="saveFile(event)">
                <input type="text" id="file_name" name="file_name" placeholder="Nama file..."
                    value="<?= htmlspecialchars($fileName) ?>"
                    class="w-full bg-[#252526] border border-[#3e3e3e] rounded px-4 py-2 text-sm focus:border-[#569cd6] focus:ring-1 focus:ring-[#569cd6] outline-none">
            
                <div class="code-editor">
                    <div id="lineNumbers">1</div>
                    <textarea id="content" name="content" spellcheck="false" wrap="off" onkeydown="handleTab(event)"><?= htmlspecialchars($fileContent) ?></textarea>
                </div>
            </form>

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="button" onclick="saveFile(event)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-md transition duration-200">
                    <i class="fas fa-save mr-1"></i>
                </button>
                <button type="button" onclick="uploadFiles()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-md transition duration-200">
                    <i class="fas fa-upload mr-1"></i>
                </button>
                <button type="button" onclick="copyText(event)" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-3 py-2 rounded-lg text-sm transition duration-200">
                    <i class="fas fa-copy"></i>
                </button>

<button type="button" onclick="undoText(event)" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-3 py-2 rounded-lg text-sm transition duration-200">
    <i class="fas fa-undo"></i>
</button>
<button type="button" onclick="redoText(event)" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-3 py-2 rounded-lg text-sm transition duration-200">
    <i class="fas fa-redo"></i>
</button>


                <button type="button" onclick="pasteText(event)" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-3 py-2 rounded-lg text-sm transition duration-200">
                    <i class="fas fa-paste"></i>
                </button>
                <button type="button" onclick="clearText(event)" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-3 py-2 rounded-lg text-sm transition duration-200">
                    <i class="fas fa-broom"></i>
                </button>

                <?php if ($fileName): ?>
                    <a href="?directory=<?= urlencode($relativeDir) ?>" class="bg-gray-700 hover:bg-gray-600 text-dark-text px-4 py-2 rounded-lg text-sm font-medium transition duration-200">
                        <i class="fas fa-times mr-1"></i>
                    </a>
                    <a href="?delete=<?= urlencode($fileName) ?>&directory=<?= urlencode($relativeDir) ?>"
                        onclick="return confirm('Yakin hapus file: <?= htmlspecialchars($fileName) ?>?')" 
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-md transition duration-200">
                        <i class="fas fa-trash-alt mr-1"></i>
                    </a>
<?php endif; ?>
<button type="button" onclick="visitPage(event)" 
    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-md transition duration-200">
    <i class="fas fa-external-link-alt mr-1"></i>
</button>

        <a href="download.php?directory=<?= urlencode($relativeDir) ?>&file=<?= urlencode($fileName) ?>"
              class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-md transition duration-200">
              <i class="fas fa-download mr-1"></i> 
            </a>
                
            </div>
        </div>
        


        <div class="lg:w-1/3 bg-[#252526] p-5 rounded-lg shadow-xl">
            <div class="flex justify-between items-center mb-4 border-b pb-2 border-[#3e3e3e]">
                <h2 class="text-xl font-semibold text-accent-blue flex items-center">
                    📂 Direktori
                </h2>
                <button onclick="createFolderPrompt()" class="bg-green-600 hover:bg-green-700 text-white w-8 h-8 rounded-full flex items-center justify-center transition duration-200" title="Buat Folder Baru">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            
            <ul class="space-y-2 file-list-container overflow-y-auto pr-2">
                <?php 
                $parentDir = getRelativePath(dirname($directory)); 
                if (rtrim($directory, '/') !== rtrim($documentRoot, '/') && rtrim($relativeDir, '/') !== '/'): 
                ?>
                    <li class="flex justify-between items-center bg-[#3c3c3c] px-4 py-3 rounded-lg hover:bg-[#4c4c4c] transition duration-150 border border-dark-border">
                        <a href="?directory=<?= urlencode($parentDir) ?>" class="text-yellow-400 font-medium hover:text-yellow-300 transition duration-150 flex items-center">
                            <i class="fas fa-level-up-alt mr-2"></i> .. (Kembali)
                        </a>
                    </li>
                <?php endif; ?>

                <?php foreach ($files as $file): ?>
                    <?php $isDir = is_dir($directory . $file); ?>
                    <li class="flex justify-between items-center bg-dark-bg px-4 py-3 rounded-lg hover:bg-[#303030] transition duration-150 border border-dark-border">
                        <?php if ($isDir): ?>
                            <a href="?directory=<?= urlencode(rtrim($relativeDir, '/') . '/' . $file) ?>" class="text-green-400 font-medium hover:text-green-300 transition duration-150 flex items-center truncate max-w-[70%]">
                                <i class="fas fa-folder mr-2"></i> <?= htmlspecialchars($file) ?>
                            </a>
                            <button onclick="showFileModal('<?= htmlspecialchars($file) ?>', true)" 
                                    class="text-dark-text-sec hover:text-white transition duration-150" title="Opsi">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        <?php else: ?>
                            <a href="?file=<?= urlencode($file) ?>&directory=<?= urlencode($relativeDir) ?>" class="text-blue-400 hover:text-blue-300 transition duration-150 flex items-center truncate max-w-[70%]">
                                <i class="fas fa-file-code mr-2"></i> <?= htmlspecialchars($file) ?>
                            </a>
                            <button onclick="showFileModal('<?= htmlspecialchars($file) ?>', false)" 
                                    class="text-dark-text-sec hover:text-white transition duration-150" title="Opsi">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div id="fileModal" class="modal-overlay" onclick="if (event.target.id === 'fileModal') hideFileModal()">
  <div class="modal-content">
    <h3 id="modalTitle" class="text-xl font-bold mb-4 text-accent-blue truncate"></h3>
    <div id="modalContent" class="space-y-3">
        <a id="modalEdit" href="#" class="block bg-blue-600 hover:bg-blue-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-edit mr-2"></i> Edit</a>
        <a id="modalRename" href="#" onclick="renameFilePrompt(this.dataset.itemname, this.dataset.isdir)" class="block bg-gray-600 hover:bg-gray-700 text-dark-text py-2 px-3 rounded-lg text-sm text-center" data-itemname="" data-isdir="false"><i class="fas fa-i-cursor mr-2"></i> Rename</a>
        <a id="modalView" href="#" target="_blank" class="block bg-teal-600 hover:bg-teal-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-external-link-alt mr-2"></i> Lihat/Kunjungi</a>
        <a id="modalDownload" href="#" class="block bg-yellow-600 hover:bg-yellow-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-download mr-2"></i> Download</a>
        <a id="modalDelete" href="#" onclick="return confirm('Apakah Anda yakin ingin menghapus?')" class="block bg-red-600 hover:bg-red-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-trash-alt mr-2"></i> Hapus</a>
        
        <a id="modalBrowse" href="#" class="hidden block bg-green-600 hover:bg-green-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-folder-open mr-2"></i> Buka Folder</a>
        <a id="modalZip" href="#" class="hidden block bg-purple-600 hover:bg-purple-700 text-white py-2 px-3 rounded-lg text-sm text-center"><i class="fas fa-file-archive mr-2"></i> Unduh as Zip</a>
    </div>
    <button onclick="hideFileModal()" class="w-full mt-4 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded-lg text-sm">Tutup</button>
  </div>
</div>


<div id="notifBox" style="display:none;position:fixed;bottom:20px;right:20px;background:#2ecc71;color:white;padding:12px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.5);font-weight:600;z-index:9999;transition:opacity .3s;"></div>

<script>
document.getElementById("titleReload").addEventListener("click", () => {
    location.reload();
});

const currentRelativeDir = '<?= urlencode($relativeDir) ?>';
let historyStack = ['']; // History stack (riwayat)
let historyIndex = 0;
const MAX_HISTORY = 50; // Batasi maksimal 50 langkah riwayat



function downloadCurrentFile(e) {
    if (e) e.preventDefault();

    const fileName = document.getElementById("file_name")?.value || "";
    if (!fileName.trim()) {
        showNotif("Tidak ada file yang sedang dibuka.", "#e74c3c");
        return;
    }

    const encodedFile = encodeURIComponent(fileName);
    const encodedDir = encodeURIComponent(currentRelativeDir);

    window.location.href = `?dl_file=${encodedFile}&directory=${encodedDir}`;
}


function visitPage(e) {
    if (e) e.preventDefault();

    // Ambil path file dari PHP (harus ada variabel ini di PHP)
    const fileUrl = document.getElementById("currentFilePath")?.value;

    if (!fileUrl) {
        showNotif("Tidak ada file untuk dibuka.", "#e74c3c");
        return;
    }

    // Buka file di tab baru
    window.open(fileUrl, "_blank");
}

function trackHistory() {
    const textarea = document.getElementById('content');
    if (!textarea) return;

    const currentValue = textarea.value;

    // Jika perubahan sama dengan riwayat terakhir, abaikan
    if (currentValue === historyStack[historyIndex]) return;

    // Hapus riwayat "Redo" jika ada perubahan baru
    historyStack.splice(historyIndex + 1);
    
    // Tambahkan state baru
    historyStack.push(currentValue);
    
    // Batasi ukuran stack
    if (historyStack.length > MAX_HISTORY) {
        historyStack.shift(); // Hapus item tertua
    } else {
        historyIndex++;
    }
}


// === LINE NUMBERS SYNC ===
function updateLineNumbers() {
    const textarea = document.getElementById('content');
    const lineNumbersDiv = document.getElementById('lineNumbers');
    if (!textarea || !lineNumbersDiv) return;

    const lines = textarea.value.split('\n');
    let numbers = '';
    for (let i = 1; i <= lines.length; i++) {
        numbers += `<div>${i}</div>`;
    }
    lineNumbersDiv.innerHTML = numbers || '<div>1</div>'; 
    lineNumbersDiv.scrollTop = textarea.scrollTop;
}

// === INIT ===
document.addEventListener('DOMContentLoaded', () => {
    const textarea = document.getElementById('content');
    if (textarea) {
        textarea.focus();
        updateLineNumbers();
        ['input','scroll','keydown','click','paste','cut'].forEach(ev => 
            textarea.addEventListener(ev, updateLineNumbers)
        );
    }
});

// Mengganti tab default dengan 4 spasi
function handleTab(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const t = e.target;
        const start = t.selectionStart;
        const end = t.selectionEnd;
        t.value = t.value.substring(0, start) + '    ' + t.value.substring(end);
        t.selectionStart = t.selectionEnd = start + 4;
        updateLineNumbers();
    }
}

// === UTILS ===
function showNotif(msg, color = '#2ecc71') {
    const box = document.getElementById('notifBox');
    box.innerText = msg; box.style.background = color; box.style.display = 'block'; box.style.opacity = '1';
    setTimeout(() => { box.style.opacity = '0'; setTimeout(() => box.style.display = 'none', 300); }, 2500);
}

// === EDITOR ACTIONS ===

function saveFile(e) { 
    if (e) e.preventDefault();
    const name = document.getElementById('file_name').value.trim();
    const content = document.getElementById('content').value;
    if (!name) return showNotif('Nama file wajib diisi!', '#e74c3c');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    const formData = new FormData();
    formData.append('file_name', name);
    formData.append('content', content);

    xhr.onload = () => {
        if (xhr.status === 200) {
            const res = JSON.parse(xhr.responseText);
            showNotif(res.message, res.success ? '#2ecc71' : '#e74c3c');
            if (res.success && res.isNew) {
                setTimeout(() => location.href = `?file=${encodeURIComponent(name)}&directory=${currentRelativeDir}`, 2000);
            }
        } else {
            showNotif('Terjadi error saat komunikasi server.', '#e74c3c');
        }
        updateLineNumbers();
    };
    xhr.send(formData);
}

function uploadFiles() {
    const input = document.createElement('input'); input.type = 'file'; input.multiple = true;
    input.accept = '*/*'; 
    input.onchange = () => {
        if (input.files.length === 0) return;
        
        const formData = new FormData();
        for (const file of input.files) formData.append('files[]', file);
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '', true);
        
        xhr.onload = () => {
            if (xhr.status === 200) {
                const res = JSON.parse(xhr.responseText);
                showNotif(res.message, res.success ? '#2ecc71' : '#e74c3c');
                if (res.success) setTimeout(() => location.reload(), 1500);
            } else {
                showNotif('Terjadi error saat upload file.', '#e74c3c');
            }
        };
        xhr.send(formData);
    };
    input.click();
}

function copyText(e) { 
    if (e) e.preventDefault();
    const t = document.getElementById('content'); 
    t.select(); 
    try {
        document.execCommand('copy');
        showNotif('Teks disalin!');
    } catch (err) {
        showNotif('Gagal menyalin. Silakan salin manual.', '#f39c12');
    }
}

function pasteText(event) {
  if (event) event.preventDefault();
  const textarea = document.getElementById('content');
  navigator.clipboard.readText().then(text => {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    textarea.value = textarea.value.substring(0, start) + text + textarea.value.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
    textarea.focus(); // Kembalikan fokus
    showNotif('Teks ditempel dari clipboard.');
updateLineNumbers(); 
  }).catch(err => {
    showNotif('Gagal menempel teks: ' + err, '#f39c12');
  });
}

function undoText(e) {
    if (e) e.preventDefault();
    const t = document.getElementById('content');

    // Menggunakan perintah bawaan elemen form (paling andal)
    t.focus();
    document.execCommand('undo'); 
    
    // Memperbarui UI
    updateLineNumbers();
    showNotif('Undo berhasil.', '#f39c12');
}

function redoText(e) {
    if (e) e.preventDefault();
    const t = document.getElementById('content');

    // Menggunakan perintah bawaan elemen form (paling andal)
    t.focus();
    document.execCommand('redo'); 
    
    // Memperbarui UI
    updateLineNumbers();
    showNotif('Redo berhasil.', '#f39c12');
}

function clearText(e) {
    if (e) e.preventDefault();

    const textarea = document.getElementById('content');

    // Pilih semua teks
    textarea.select();

    // Hapus dengan cara yang undo-friendly
    textarea.setRangeText("");

    showNotif('Teks dibersihkan.', '#f39c12');
    updateLineNumbers();
}


// === FILE MANAGER ACTIONS ===

function createFolderPrompt() {
    const folderName = prompt("Masukkan nama folder baru:");
    if (!folderName) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onload = () => {
        if (xhr.status === 200) {
            const res = JSON.parse(xhr.responseText);
            showNotif(res.message, res.success ? '#2ecc71' : '#e74c3c');
            if (res.success) setTimeout(() => location.reload(), 1500);
        } else {
            showNotif('Terjadi error saat membuat folder.', '#e74c3c');
        }
    };
    xhr.send(`new_folder=${encodeURIComponent(folderName)}`);
}

function renameFilePrompt(oldName, isDir) {
    hideFileModal();
    const newItemType = isDir === 'true' ? 'folder' : 'file';
    const newName = prompt(`Masukkan nama baru untuk ${newItemType} "${oldName}":`, oldName);
    
    if (!newName || newName === oldName) return;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    
    const formData = new FormData();
    formData.append('rename_item', oldName);
    formData.append('new_name', newName);

    xhr.onload = () => {
        if (xhr.status === 200) {
            const res = JSON.parse(xhr.responseText);
            showNotif(res.message, res.success ? '#2ecc71' : '#e74c3c');
            if (res.success) setTimeout(() => location.reload(), 1500);
        } else {
            showNotif('Terjadi error saat rename item.', '#e74c3c');
        }
    };
    xhr.send(formData);
}

// === MODAL CONTROL ===

function showFileModal(itemName, isDir) {
    const encodedItemName = encodeURIComponent(itemName);
    // Path URL publik relatif dari document root
    const itemPath = `/${decodeURIComponent(currentRelativeDir).replace(/^\/+/, '')}${encodedItemName}`;

    document.getElementById('modalTitle').innerText = itemName;

    // Link Dasar
    document.getElementById('modalRename').dataset.itemname = itemName;
    document.getElementById('modalRename').dataset.isdir = isDir;
    document.getElementById('modalDelete').href = `?delete=${encodedItemName}&directory=${currentRelativeDir}`;
    
    // Reset Visibility
    document.getElementById('modalEdit').style.display = 'none';
    document.getElementById('modalView').style.display = 'none';
    document.getElementById('modalDownload').style.display = 'none';
    document.getElementById('modalBrowse').style.display = 'none';
    document.getElementById('modalZip').style.display = 'none';
    
    if (isDir) {
        // Opsi Folder
        document.getElementById('modalBrowse').href = `?directory=${currentRelativeDir}${encodedItemName}`;
        document.getElementById('modalZip').href = `?zip_dir=${currentRelativeDir}${encodedItemName}`;
        document.getElementById('modalView').href = itemPath;
        
        document.getElementById('modalBrowse').style.display = 'block';
        document.getElementById('modalZip').style.display = 'block';
        document.getElementById('modalView').style.display = 'block';
    } else {
        // Opsi File
        document.getElementById('modalEdit').href = `?file=${encodedItemName}&directory=${currentRelativeDir}`;
        document.getElementById('modalView').href = itemPath; 
        document.getElementById('modalDownload').href = `?download_file=${encodedItemName}&directory=${currentRelativeDir}`;
        
        document.getElementById('modalEdit').style.display = 'block';
        document.getElementById('modalView').style.display = 'block';
        document.getElementById('modalDownload').style.display = 'block';
    }

    document.getElementById('fileModal').style.display = 'flex';
}

function hideFileModal() {
    document.getElementById('fileModal').style.display = 'none';
}

</script>

</body>
</html>