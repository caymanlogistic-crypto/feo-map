<?php include 'menu2.php'; ?>
<div id="map"></div>

<button class="refresh-btn" id="refreshBtn" title="Обновить позиции транспорта">Обновить транспорт</button>

<div class="routes-panel">
    <div class="routes-section routes-section-planned">
        <h3 class="routes-title-planned">Планируемые маршруты</h3>
        <div id="plannedRoutesList" class="routes-list"><div class="route-list-empty">Загрузка...</div></div>
    </div>
    <div class="routes-section routes-section-found">
        <h3 class="routes-title-found">Исполнит. найден</h3>
        <div id="foundRoutesList" class="routes-list"><div class="route-list-empty">Загрузка...</div></div>
    </div>
    <div class="routes-section routes-section-started">
        <h3 class="routes-title-started">Вывоз начался</h3>
        <div id="startedRoutesList" class="routes-list"><div class="route-list-empty">Загрузка...</div></div>
    </div>
</div>

<div class="layer-panel">
    <div class="layer-group manager-scope-group">
        <div class="layer-group-title">Менеджер</div>
        <select id="managerScopeSelect" class="manager-scope-select">
            <option value="">Показать все</option>
        </select>
        <div class="manager-scope-error" id="managerScopeError" style="display:none;"></div>
    </div>
    <h3>Управление слоями</h3>
    <div class="layer-group">
        <div class="layer-group-title">Рейсы</div>
        <div class="checkbox-item"><input type="checkbox" id="no_flight_default" checked onchange="filterByCustomLayer('default', this.checked)"><label for="no_flight_default">Доступно к вывозу</label><div class="color-indicator" style="background: #000000"></div></div>
        <?php if (isset($flightStatusList['planned_route'])): ?>
        <div class="checkbox-item"><input type="checkbox" id="flight_status_planned_route" checked onchange="filterByFlightStatus('planned_route', this.checked)"><label for="flight_status_planned_route">Планируемые маршруты</label><div class="color-indicator" style="background: #9c27b0"></div></div>
        <?php endif; ?>
        <?php foreach ($flightStatusList as $status => $v): ?>
        <?php if ($status === 'planned_route') continue; ?>
        <div class="checkbox-item"><input type="checkbox" id="flight_status_<?= $status ?>" <?= ($status == 'started' || $status == 'completed') ? '' : 'checked' ?> onchange="filterByFlightStatus('<?= $status ?>', this.checked)"><label for="flight_status_<?= $status ?>"><?= htmlspecialchars($statusNames[$status] ?? $status) ?></label><div class="color-indicator" style="background: <?= $statusColors[$status] ?? '#000000' ?>"></div></div>
        <?php endforeach; ?>
    </div>
    <?php if ($hasDefault || !empty($customLayers)): ?>
    <div class="layer-group"><div class="layer-group-title">Дополнительно</div>
        <?php foreach ($customLayers as $ln => $ld): ?>
        <div class="checkbox-item"><input type="checkbox" id="custom_layer_<?= md5($ln) ?>" checked onchange="filterByCustomLayer('<?= addslashes($ln) ?>', this.checked)"><label for="custom_layer_<?= md5($ln) ?>"><?= htmlspecialchars($ln) ?></label><div class="color-indicator" style="background: <?= $ld['color'] ?>"></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="layer-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 5px;">
        <div class="layer-group-title">Транспорт (Slitex)</div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_active" value="active" checked onchange="setTransportDisplayMode('active')">
            <label for="transport_mode_active">Показать только активный транспорт</label>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_all" value="all" onchange="setTransportDisplayMode('all')">
            <label for="transport_mode_all">Показать весь транспорт</label>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_none" value="none" onchange="setTransportDisplayMode('none')">
            <label for="transport_mode_none">Не отображать транспорт</label>
        </div>
    </div>

    <div class="layer-group" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;"><div class="checkbox-item" style="background: #fff3cd; border: 1px solid #ffc107;"><input type="checkbox" id="disable-popups" checked onchange="togglePopups(this.checked)"><label for="disable-popups">Не показывать попапы</label></div></div>
</div>

<div class="selection-panel" id="selection-panel">
    <h4>Выделенные заявки</h4>
    <div id="stats-box">Заявок: 0 • Адресов: 0</div>
    <div id="total-weight">Общий вес: 0 кг</div>
    <div id="route-edit-status"><span style="color:#28a745;">Создание нового маршрута</span></div>
    <input type="hidden" id="route-cost-input" value="">
    <div id="route-info-container"></div>
    <div id="selected-list"></div>
    <div class="hint">Введите ID через запятую или Enter</div>
    <textarea id="route-input" class="manual-input" placeholder="ID заявок..."></textarea>
    <div class="route-buttons">
        <button class="route-btn calculate-route-btn" id="calc-route-btn" onclick="calculateRoute()" disabled>Рассчитать</button>
        <button class="route-btn save-route-btn" id="saveRouteBtn" onclick="promptSaveRoute()" disabled>Сохранить маршрут</button>
    </div>
    <button class="clear-btn" onclick="clearSelection()">Очистить выделение</button>
</div>

<div class="flight-modal-backdrop" id="flightEditModal" style="display:none;">
    <div class="flight-modal">
        <div class="flight-modal-header">
            <div class="flight-modal-title" id="flightEditTitle">Редактирование рейса</div>
            <button class="route-action-btn route-icon-btn" id="flightEditCloseTopBtn">✕</button>
        </div>

        <input type="hidden" id="edit_flight_id" value="">
        <input type="hidden" id="edit_flight_source" value="">
        <input type="hidden" id="edit_current_status" value="">

        <div class="flight-summary" id="flightLiveSummary"></div>

        <div class="flight-modal-grid">
            <div>
                <label class="flight-modal-label" for="edit_comment">Комментарий / заголовок</label>
                <input class="flight-modal-input" type="text" id="edit_comment">
            </div>
            <div>
                <label class="flight-modal-label" for="edit_driver_id">Водитель / машина</label>
                <select class="flight-modal-input" id="edit_driver_id"></select>
            </div>
            <div class="flight-date-range" id="plannedDateRangeWrap">
                <label class="flight-modal-label" id="plannedDateRangeTitle" for="edit_planned_start_date_from">Вывоз запланирован на даты</label>
                <div class="flight-date-range-row">
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_planned_start_date_from">С</label>
                        <input class="flight-modal-input" type="date" id="edit_planned_start_date_from">
                    </div>
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_planned_start_date_to">По</label>
                        <input class="flight-modal-input" type="date" id="edit_planned_start_date_to">
                    </div>
                </div>
            </div>
            <div class="flight-date-range" id="actualDateRangeWrap" style="display:none;">
                <label class="flight-modal-label" id="actualDateRangeTitle" for="edit_actual_start_date">Фактические даты перевозки</label>
                <div class="flight-date-range-row">
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_actual_start_date">С</label>
                        <input class="flight-modal-input" type="date" id="edit_actual_start_date">
                    </div>
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_actual_end_date">По</label>
                        <input class="flight-modal-input" type="date" id="edit_actual_end_date">
                    </div>
                </div>
            </div>
            <div>
                <label class="flight-modal-label" for="edit_cost">Стоимость</label>
                <input class="flight-modal-input" type="number" id="edit_cost" step="0.01" min="0">
            </div>
            <div>
                <label class="flight-modal-label" for="edit_zayavki_ids">Список заявок</label>
                <textarea class="flight-modal-input" id="edit_zayavki_ids" rows="3" placeholder="101,104,105"></textarea>
            </div>
        </div>

        <div class="flight-change-preview" id="flightChangePreview" style="display:none;"></div>
        <div class="flight-validation-errors" id="flightValidationErrors" style="display:none;"></div>

        <div class="workflow-section">
            <div class="workflow-title">Изменение данных рейса</div>
            <div class="workflow-desc">Изменения полей сохраняются в карточке рейса. Для статуса «Исполнит. найден» изменения отправляются в MAX.</div>
            <button class="route-action-btn route-edit-btn route-action-main" id="flightEditSaveBtn">Сохранить изменения</button>
        </div>

        <div class="workflow-section">
            <div class="workflow-title">Смена состояния рейса</div>

            <div class="workflow-action" id="workflowToFoundWrap">
                <div class="workflow-subtitle">Перевести в «Исполнитель найден»</div>
                <div class="workflow-desc">После перевода рейс считается согласованным. Будут зафиксированы водитель, даты, стоимость и заявки. В MAX отправится уведомление, начнётся подготовка транспортных документов.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditTransferFoundBtn">Перевести в «Исполнит. найден»</button>
            </div>

            <div class="workflow-action" id="workflowToStartedWrap">
                <div class="workflow-subtitle">Начать выполнение маршрута</div>
                <div class="workflow-desc">Рейс перейдёт в статус «Вывоз начался». Подключается контроль выполнения перевозки и логика трекера. В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditTransferStartedBtn">Перевести в «Вывоз начался»</button>
            </div>

            <div class="workflow-action" id="workflowBackToPlannedWrap">
                <div class="workflow-subtitle">Вернуть в планирование</div>
                <div class="workflow-desc">Рейс будет возвращён в планирование. Подготовку документов нужно проверить или приостановить. В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditBackToPlannedBtn">Вернуть в «Планируемый»</button>
            </div>

            <div class="workflow-action" id="workflowBackToFoundWrap">
                <div class="workflow-subtitle">Вернуть к найденному исполнителю</div>
                <div class="workflow-desc">Рейс будет возвращён из выполнения в статус «Исполнит. найден». В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditBackToFoundBtn">Вернуть в «Исполнит. найден»</button>
            </div>
            <div class="workflow-action" id="workflowToCompletedWrap">
                <div class="workflow-subtitle">Перевести в «Груз сдан»</div>
                <div class="workflow-desc">Рейс будет завершён. Для перевода укажите дату завершения перевозки. В MAX будет отправлено уведомление о завершении рейса.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditToCompletedBtn">Перевести в «Груз сдан»</button>
            </div>
        </div>

        <div class="danger-zone" id="workflowDeleteWrap">
            <div class="workflow-title">Опасная зона</div>
            <div class="workflow-desc">Удаление доступно только для планируемого рейса. Действие удалит маршрут из списка планируемых маршрутов.</div>
            <button class="route-action-btn route-manage-danger route-action-main" id="flightEditDeleteBtn">Удалить рейс</button>
        </div>

        <div class="flight-modal-footer">
            <button class="route-action-btn route-action-main" id="flightEditCancelBtn">Закрыть</button>
        </div>
    </div>
</div>

<div class="flight-modal-backdrop" id="startConfirmModal" style="display:none;">
    <div class="flight-modal flight-modal-confirm">
        <div class="flight-modal-title" id="transitionConfirmTitle">Подтверждение действия</div>
        <input type="hidden" id="start_flight_id" value="">
        <input type="hidden" id="start_target_status" value="">
        <label class="flight-modal-label" id="transitionConfirmDateLabel" for="start_actual_start_date">Дата начала вывоза</label>
        <input class="flight-modal-input" type="date" id="start_actual_start_date">
        <div class="flight-change-preview" id="transitionConfirmPreview"></div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-transfer-start-btn route-action-main" id="startConfirmBtn">Подтвердить</button>
            <button class="route-action-btn route-action-main" id="startCancelBtn">Отмена</button>
        </div>
    </div>
</div>
