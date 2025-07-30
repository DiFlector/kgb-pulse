<?php
// Файловый менеджер - Администратор
session_start();

// Проверка авторизации
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: /lks/login.php');
    exit;
}

// Проверка прав администратора
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'SuperUser')) {
    header('Location: /lks/login.php');
    exit;
}

require_once __DIR__ . '/../../php/db/Database.php';
require_once __DIR__ . '/../../php/common/Auth.php';

$auth = new Auth();
$currentUser = $auth->getCurrentUser();

if (!$currentUser) {
    header('Location: /lks/login.php');
    exit;
}

// Функция для получения размера директории
function getDirSize($dir) {
    $size = 0;
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $filepath = $dir . '/' . $file;
                if (is_dir($filepath)) {
                    $size += getDirSize($filepath);
                } else {
                    $size += filesize($filepath);
                }
            }
        }
    }
    return $size;
}

// Функция для форматирования размера файла
function formatBytes($size, $precision = 2) {
    $base = log($size, 1024);
    $suffixes = array('', 'K', 'M', 'G', 'T');   
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)] . 'B';
}

// Получение информации о директориях
$baseDir = __DIR__ . '/../../files/';
$directories = [
    'excel' => 'Excel файлы',
    'pdf' => 'PDF файлы',
    'results' => 'Результаты',
    'protocol' => 'Протоколы',
    'template' => 'Шаблоны',
    'polojenia' => 'Положения',
    'sluzebnoe' => 'Служебные файлы'
];

$fileInfo = [];
foreach ($directories as $dir => $title) {
    $dirPath = $baseDir . $dir;
    if (is_dir($dirPath)) {
        $files = array_diff(scandir($dirPath), array('.', '..'));
        $fileInfo[$dir] = [
            'title' => $title,
            'count' => count($files),
            'size' => getDirSize($dirPath),
            'files' => []
        ];
        
        foreach ($files as $file) {
            $filepath = $dirPath . '/' . $file;
            if (is_file($filepath)) {
                $fileInfo[$dir]['files'][] = [
                    'name' => $file,
                    'size' => filesize($filepath),
                    'modified' => filemtime($filepath)
                ];
            }
        }
    } else {
        $fileInfo[$dir] = [
            'title' => $title,
            'count' => 0,
            'size' => 0,
            'files' => []
        ];
    }
}

$pageTitle = 'Менеджер файлов';
$pageHeader = 'Менеджер файлов';
$pageActions = '
                        <button type="button" class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-upload"></i> Загрузить файл
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                            <i class="bi bi-folder-plus"></i> Создать папку
                        </button>
';

include '../includes/header.php';
?>

<!-- Объявляем функции в самом начале, до любого HTML -->
<script>
// Определяем все функции файлового менеджера в глобальной области видимости
window.downloadFile = function(folder, fileName) {
    window.open('/lks/files/' + folder + '/' + fileName, '_blank');
};

window.renameFile = function(folder, fileName) {
    const newName = prompt('Введите новое имя файла:', fileName);
    if (newName && newName !== fileName) {
        const data = {
            action: 'rename',
            folder: folder,
            old_name: fileName,
            new_name: newName
        };
        
        fetch('/lks/php/admin/manage-files.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Произошла ошибка при переименовании файла');
        });
    }
};

window.deleteFile = function(folder, fileName) {
    if (confirm('Вы уверены, что хотите удалить файл "' + fileName + '"?')) {
        const data = {
            action: 'delete',
            folder: folder,
            filename: fileName
        };
        
        fetch('/lks/php/admin/manage-files.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Произошла ошибка при удалении файла');
        });
    }
};

window.uploadToFolder = function(folderName) {
    document.getElementById('folderSelect').value = folderName;
    const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
    uploadModal.show();
};

window.uploadFiles = function() {
    const formData = new FormData();
    const folder = document.getElementById('folderSelect').value;
    const files = document.getElementById('fileInput').files;
    
    if (files.length === 0) {
        alert('Выберите файлы для загрузки');
        return;
    }
    
    formData.append('folder', folder);
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }
    
    // Показываем индикатор загрузки
    const modal = document.getElementById('uploadModal');
    const submitBtn = modal.querySelector('button[onclick="uploadFiles()"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Загрузка...';
    
    fetch('/lks/php/admin/upload-file.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Произошла ошибка при загрузке файлов');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Загрузить';
    });
};

window.createFolder = function() {
    const folderName = document.getElementById('folderName').value;
    const folderDescription = document.getElementById('folderDescription').value;
    
    if (!folderName) {
        alert('Введите название папки');
        return;
    }
    
    const data = {
        action: 'create_folder',
        folder_name: folderName,
        description: folderDescription
    };
    
    fetch('/lks/php/admin/manage-files.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Произошла ошибка при создании папки');
    });
};

window.clearFolder = function(folderName) {
    if (confirm('Вы уверены, что хотите очистить папку "' + folderName + '"? Все файлы будут удалены!')) {
        const data = {
            action: 'clear_folder',
            folder: folderName
        };
        
        fetch('/lks/php/admin/manage-files.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Произошла ошибка при очистке папки');
        });
    }
};

window.downloadFolder = function(folderName) {
    if (confirm('Скачать папку "' + folderName + '" в виде архива?')) {
        window.open('/lks/php/admin/download-folder.php?folder=' + folderName, '_blank');
    }
};

window.renameFolder = function(folderName) {
    const newName = prompt('Введите новое название папки:', folderName);
    if (newName && newName !== folderName) {
        const data = {
            action: 'rename_folder',
            old_name: folderName,
            new_name: newName
        };
        
        fetch('/lks/php/admin/manage-files.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Ошибка: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Произошла ошибка при переименовании папки');
        });
    }
};

window.deleteFolder = function(folderName) {
    if (confirm('Вы уверены, что хотите ПОЛНОСТЬЮ УДАЛИТЬ папку "' + folderName + '" со всем содержимым?\n\nЭто действие нельзя отменить!')) {
        const secondConfirm = confirm('ВНИМАНИЕ! Папка "' + folderName + '" и ВСЕ файлы в ней будут удалены навсегда!\n\nПродолжить?');
        if (secondConfirm) {
            const data = {
                action: 'delete_folder',
                folder: folderName
            };
            
            fetch('/lks/php/admin/manage-files.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка при удалении папки');
            });
        }
    }
};

// Создаем глобальные алиасы для совместимости с onclick
function downloadFile(folder, fileName) { return window.downloadFile(folder, fileName); }
function renameFile(folder, fileName) { return window.renameFile(folder, fileName); }
function deleteFile(folder, fileName) { return window.deleteFile(folder, fileName); }
function uploadToFolder(folderName) { return window.uploadToFolder(folderName); }
function uploadFiles() { return window.uploadFiles(); }
function createFolder() { return window.createFolder(); }
function clearFolder(folderName) { return window.clearFolder(folderName); }
function downloadFolder(folderName) { return window.downloadFolder(folderName); }
function renameFolder(folderName) { return window.renameFolder(folderName); }
function deleteFolder(folderName) { return window.deleteFolder(folderName); }

// Функции инициализации после загрузки DOM
document.addEventListener('DOMContentLoaded', function() {
    // Файловый менеджер готов к работе
});
</script>

                <!-- Статистика -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title">Всего папок</h5>
                                <h3><?= count($directories) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Всего файлов</h5>
                                <h3><?= array_sum(array_column($fileInfo, 'count')) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title">Общий размер</h5>
                                <h3><?= formatBytes(array_sum(array_column($fileInfo, 'size'))) ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h5 class="card-title">Свободное место</h5>
                                <h3><?= formatBytes(disk_free_space(__DIR__)) ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Папки с файлами -->
<div class="row file-manager">
                    <?php foreach ($fileInfo as $dir => $info): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h6 class="mb-0">
                                    <i class="bi bi-folder text-warning"></i>
                                    <?= $info['title'] ?>
                                </h6>
                                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="uploadToFolder('<?= $dir ?>')">
                            <i class="bi bi-upload me-2"></i>Загрузить файл
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadFolder('<?= $dir ?>')">
                            <i class="bi bi-download me-2"></i>Скачать папку
                        </a></li>
                        <li><a class="dropdown-item" href="#" onclick="renameFolder('<?= $dir ?>')">
                            <i class="bi bi-pencil me-2"></i>Переименовать папку
                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-warning" href="#" onclick="clearFolder('<?= $dir ?>')">
                            <i class="bi bi-trash me-2"></i>Очистить
                        </a></li>
                        <li><a class="dropdown-item text-danger" href="#" onclick="deleteFolder('<?= $dir ?>')">
                            <i class="bi bi-folder-x me-2"></i>Удалить папку
                        </a></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <small class="text-muted">
                                        Файлов: <strong><?= $info['count'] ?></strong> | 
                                        Размер: <strong><?= formatBytes($info['size']) ?></strong>
                                    </small>
                                </div>
                                
                                <?php if (empty($info['files'])): ?>
                                    <p class="text-muted text-center py-3">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        Папка пуста
                                    </p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach ($info['files'] as $file): ?>
                                        <div class="list-group-item p-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold" style="font-size: 0.9em;">
                                                        <?= htmlspecialchars($file['name']) ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?= formatBytes($file['size']) ?> | 
                                                        <?= date('d.m.Y H:i', $file['modified']) ?>
                                                    </small>
                                                </div>
                                                <div class="btn-group" role="group">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="downloadFile('<?= $dir ?>', '<?= $file['name'] ?>')">
                                                        <i class="bi bi-download"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="renameFile('<?= $dir ?>', '<?= $file['name'] ?>')">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteFile('<?= $dir ?>', '<?= $file['name'] ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
    </div>

    <!-- Модальное окно загрузки файла -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Загрузить файл</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="folderSelect" class="form-label">Папка назначения</label>
                            <select class="form-select" id="folderSelect" required>
                                <?php foreach ($directories as $dir => $title): ?>
                                <option value="<?= $dir ?>"><?= $title ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="fileInput" class="form-label">Выберите файл</label>
                            <input type="file" class="form-control" id="fileInput" multiple required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-success" onclick="uploadFiles()">Загрузить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно создания папки -->
    <div class="modal fade" id="createFolderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Создать новую папку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createFolderForm">
                        <div class="mb-3">
                            <label for="folderName" class="form-label">Название папки</label>
                            <input type="text" class="form-control" id="folderName" required>
                        </div>
                        <div class="mb-3">
                            <label for="folderDescription" class="form-label">Описание (необязательно)</label>
                            <textarea class="form-control" id="folderDescription" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" onclick="createFolder()">Создать</button>
                </div>
            </div>
        </div>
    </div>



<?php include '../includes/footer.php'; ?> 