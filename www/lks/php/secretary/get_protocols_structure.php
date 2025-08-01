<?php
/**
 * API для получения структуры протоколов с нумерацией
 * Файл: www/lks/php/secretary/get_protocols_structure.php
 */

require_once __DIR__ . "/../common/Auth.php";
require_once __DIR__ . "/../db/Database.php";
require_once __DIR__ . "/protocol_numbering.php";

header('Content-Type: application/json; charset=utf-8');

try {
    // Проверяем авторизацию
    $auth = new Auth();
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Не авторизован']);
        exit;
    }

    // Проверяем права секретаря
    if (!$auth->hasAnyRole(['Secretary', 'SuperUser', 'Admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
        exit;
    }

    // Получаем данные из POST запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['meroId'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан ID мероприятия']);
        exit;
    }

    $meroId = intval($input['meroId']);
    $selectedDisciplines = $input['disciplines'] ?? null;

    // Отладочная информация
    error_log("get_protocols_structure.php: meroId = " . $meroId);
    error_log("get_protocols_structure.php: selectedDisciplines = " . json_encode($selectedDisciplines));

    // Получаем информацию о мероприятии
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT class_distance FROM meros WHERE champn = ?");
    $stmt->execute([$meroId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Мероприятие не найдено']);
        exit;
    }

    $classDistance = json_decode($event['class_distance'], true);
    
    if (!$classDistance) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Некорректная структура дисциплин']);
        exit;
    }

    // Получаем структуру протоколов с нумерацией
    $structure = ProtocolNumbering::getProtocolsStructure($classDistance, $selectedDisciplines);
    
    error_log("get_protocols_structure.php: получена структура с " . count($structure) . " протоколами");
    error_log("get_protocols_structure.php: структура: " . json_encode($structure));

    // Группируем протоколы по дисциплинам для удобства отображения
    $groupedStructure = [];
    foreach ($structure as $protocol) {
        $disciplineKey = $protocol['class'] . '_' . $protocol['sex'] . '_' . $protocol['distance'];
        
        if (!isset($groupedStructure[$disciplineKey])) {
            $groupedStructure[$disciplineKey] = [
                'class' => $protocol['class'],
                'sex' => $protocol['sex'],
                'distance' => $protocol['distance'],
                'ageGroups' => []
            ];
        }
        
        $groupedStructure[$disciplineKey]['ageGroups'][] = [
            'name' => $protocol['ageGroup']['name'],
            'displayName' => $protocol['displayName'],
            'fullName' => $protocol['fullName'],
            'full_name' => $protocol['ageGroup']['full_name'] ?? $protocol['ageGroup']['name'],
            'number' => $protocol['number'],
            'minAge' => $protocol['ageGroup']['min_age'],
            'maxAge' => $protocol['ageGroup']['max_age']
        ];
    }

    // Преобразуем в массив
    $result = array_values($groupedStructure);
    
    error_log("get_protocols_structure.php: сгруппировано в " . count($result) . " дисциплин");
    error_log("get_protocols_structure.php: результат: " . json_encode($result));

    $response = [
        'success' => true,
        'structure' => $result,
        'totalProtocols' => count($structure),
        'totalDisciplines' => count($result)
    ];
    
    error_log("get_protocols_structure.php: отправляем ответ: " . json_encode($response));
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Ошибка получения структуры протоколов: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка получения структуры протоколов: ' . $e->getMessage()
    ]);
}
?> 