<?php include 'menu2.php'; ?>
<div id="map"></div>

<button class="refresh-btn" id="refreshBtn" title="Обновить позиции транспорта">Обновить транспорт</button>

<div class="routes-panel">
    <div class="routes-section routes-section-planned">
        <h3 class="routes-title-planned">Планируемые маршруты</h3>
        <div id="plannedRoutesList" class="routes-list"><div class="route-list-empty">Загрузка...</div></div>
    </div>
    <div class="routes-section routes-section-found">
        <h3 class="routes-title-found">Сформированные рейсы</h3>
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
        <div class="checkbox-item"><input type="checkbox" id="warehouse_layer_visible" checked onchange="toggleWarehouseLayer(this.checked)"><label for="warehouse_layer_visible">Склады</label><div class="color-indicator" style="background: #5c6bc0"></div></div>
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
            <label for="transport_mode_active">Транспорт в активных рейсах</label>
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
    <div class="route-buttons route-buttons-secondary">
        <button class="route-btn edit-data-btn" id="editRouteDataBtn" onclick="openSelectedRouteEditor()" disabled>Редактирование данных</button>
        <button class="route-btn clear-btn" onclick="clearSelection()">Закрыть</button>
    </div>
</div>

<div class="flight-modal-backdrop" id="flightEditModal" style="display:none;">
    <div class="flight-modal">
        <div class="flight-modal-header">
            <div class="flight-modal-title" id="flightEditTitle">Редактирование рейса</div>
            <button class="route-action-btn route-icon-btn" id="flightEditCloseTopBtn">&times;</button>
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
                <div class="driver-input-row">
                    <div class="driver-combobox" id="edit_driver_combobox">
                        <input class="flight-modal-input driver-combobox-input" type="text" id="edit_driver_input" placeholder="Выберите водителя" autocomplete="off">
                        <button type="button" class="driver-combobox-toggle" id="edit_driver_toggle" aria-label="Открыть список">▼</button>
                        <div class="driver-combobox-menu" id="edit_driver_menu" style="display:none;"></div>
                    </div>
                    <select class="flight-modal-input" id="edit_driver_id" style="display:none;"></select>
                    <button type="button" class="route-action-btn route-action-main driver-add-btn" id="add_driver_btn">+ Новый водитель</button>
                </div>
                <div class="field-inline-error" id="edit_driver_error" style="display:none;"></div>
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
            <div class="flight-cost-wrap">
                <label class="flight-modal-label" for="edit_cost">Стоимость</label>
                <input class="flight-modal-input" type="number" id="edit_cost" step="0.01" min="0">
            </div>
            <div class="flight-route-type-wrap">
                <label class="flight-modal-label" for="edit_route_type">Тип рейса</label>
                <select class="flight-modal-input" id="edit_route_type">
                    <option value="generator_to_utilizer">Отходообразователь → Утилизатор</option>
                    <option value="generator_to_warehouse">Отходообразователь → Склад</option>
                    <option value="warehouse_to_warehouse">Склад → Склад</option>
                    <option value="warehouse_to_utilizer">Склад → Утилизатор</option>
                </select>
            </div>
            <div class="flight-warehouse-group" id="edit_warehouse_group" style="display:none;">
                <div class="flight-warehouse-wrap" id="edit_source_warehouse_wrap" style="display:none;">
                    <label class="flight-modal-label" for="edit_source_warehouse_id">Склад отправления</label>
                    <div class="warehouse-input-row">
                        <select class="flight-modal-input" id="edit_source_warehouse_id"></select>
                        <button type="button" class="route-action-btn route-action-main warehouse-add-btn" id="add_source_warehouse_btn">+ Новый склад</button>
                    </div>
                </div>
                <div class="flight-warehouse-wrap" id="edit_destination_warehouse_wrap" style="display:none;">
                    <label class="flight-modal-label" for="edit_destination_warehouse_id">Склад назначения</label>
                    <div class="warehouse-input-row">
                        <select class="flight-modal-input" id="edit_destination_warehouse_id"></select>
                        <button type="button" class="route-action-btn route-action-main warehouse-add-btn" id="add_destination_warehouse_btn">+ Новый склад</button>
                    </div>
                </div>
            </div>
            <div class="flight-zayavki-wrap">
                <label class="flight-modal-label" for="edit_zayavki_ids">Список заявок</label>
                <textarea class="flight-modal-input" id="edit_zayavki_ids" rows="3" placeholder="101,104,105"></textarea>
            </div>
        </div>

        <div class="flight-change-preview" id="flightChangePreview" style="display:none;"></div>
        <div class="flight-validation-errors" id="flightValidationErrors" style="display:none;"></div>

        <div class="workflow-section workflow-card workflow-card-update" id="workflowUpdateSection">
            <div class="workflow-title" id="workflowUpdateTitle">Актуализация рейса</div>
            <div class="workflow-desc" id="workflowUpdateDesc">Изменения дат автоматически фиксируются в МАКС.</div>
            <button class="route-action-btn route-edit-btn route-action-main" id="flightEditSaveBtn">Сохранить/Обновить</button>
        </div>

        <div class="workflow-section workflow-card workflow-card-lifecycle" id="workflowLifecycleSection">

            <div class="workflow-action workflow-action-primary workflow-action-found" id="workflowToFoundWrap">
                <div class="workflow-subtitle">Сформировать рейс</div>
                <div class="workflow-desc">После перевода рейс считается согласованным. Будут зафиксированы водитель, даты, стоимость и заявки. В MAX отправится уведомление, начнётся подготовка транспортных документов.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditTransferFoundBtn">Сформировать рейс</button>
            </div>

            <div class="workflow-action workflow-action-primary workflow-action-started" id="workflowToStartedWrap">
                <div class="workflow-subtitle">Начало выполнения</div>
                <div class="workflow-desc">Рейс перейдёт в статус «Вывоз начался». Подключается контроль выполнения перевозки и логика трекера. В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditTransferStartedBtn">Подтвердить начало вывоза</button>
            </div>

            <div class="workflow-action workflow-action-secondary workflow-action-return" id="workflowBackToPlannedWrap">
                <div class="workflow-subtitle">Возврат в планирование</div>
                <div class="workflow-desc">Рейс будет возвращён в планирование. Подготовку документов нужно проверить или приостановить. В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditBackToPlannedBtn">Вернуть в планируемые</button>
            </div>

            <div class="workflow-action workflow-action-primary workflow-action-completed" id="workflowToCompletedWrap">
                <div class="workflow-subtitle">Завершение рейса</div>
                <div class="workflow-desc">После завершения рейс будет переведен в архив перевозок.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditToCompletedBtn">Перевести в Груз сдан</button>
            </div>
            <div class="workflow-action workflow-action-secondary workflow-action-danger-soft" id="workflowBackToFoundWrap">
                <div class="workflow-subtitle">Приостановить выполнение</div>
                <div class="workflow-desc">Рейс будет возвращён из выполнения в статус «Рейс сформирован». В MAX будет отправлено уведомление.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditBackToFoundBtn">Вернуть в сформированные</button>
            </div>
        </div>

        <div class="danger-zone workflow-card workflow-card-danger" id="workflowDeleteWrap">
            <div class="workflow-title">Удаление маршрута</div>
            <div class="workflow-desc">Маршрут будет удалён из списка планируемых рейсов.</div>
            <button class="route-action-btn route-manage-danger route-action-main" id="flightEditDeleteBtn">Удалить маршрут</button>
        </div>

        <div class="flight-modal-footer">
            <button class="route-action-btn route-action-main" id="flightEditCancelBtn">Закрыть</button>
        </div>
    </div>
</div>

<div class="flight-modal-backdrop" id="warehouseCreateModal" style="display:none;">
    <div class="flight-modal flight-modal-confirm warehouse-mini-modal">
        <div class="flight-modal-header">
            <div class="flight-modal-title">Новый склад</div>
            <button class="route-action-btn route-icon-btn" id="warehouseCreateCloseTopBtn">&times;</button>
        </div>
        <div class="flight-validation-errors" id="warehouseCreateErrors" style="display:none;"></div>
        <label class="flight-modal-label" for="new_warehouse_name">Название склада *</label>
        <input class="flight-modal-input" type="text" id="new_warehouse_name">
        <label class="flight-modal-label" for="new_warehouse_address">Полный адрес *</label>
        <textarea class="flight-modal-input" id="new_warehouse_address" rows="2"></textarea>
        <div class="warehouse-geocode-row">
            <button class="route-action-btn route-edit-btn route-action-main" id="warehouseGeocodeBtn">Определить координаты</button>
            <div class="warehouse-geocode-note" id="warehouseGeocodeNote" style="display:none;"></div>
        </div>
        <div class="warehouse-mini-grid">
            <div>
                <label class="flight-modal-label" for="new_warehouse_latitude">Широта</label>
                <input class="flight-modal-input" type="number" step="any" id="new_warehouse_latitude">
            </div>
            <div>
                <label class="flight-modal-label" for="new_warehouse_longitude">Долгота</label>
                <input class="flight-modal-input" type="number" step="any" id="new_warehouse_longitude">
            </div>
        </div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-transfer-start-btn route-action-main" id="warehouseCreateSaveBtn">Сохранить</button>
            <button class="route-action-btn route-action-main" id="warehouseCreateCancelBtn">Отмена</button>
        </div>
    </div>
</div>

<div class="flight-modal-backdrop" id="driverCreateModal" style="display:none;">
    <div class="flight-modal flight-modal-confirm driver-mini-modal">
        <div class="flight-modal-header">
            <div class="flight-modal-title">Новый водитель</div>
            <button class="route-action-btn route-icon-btn" id="driverCreateCloseTopBtn">&times;</button>
        </div>
        <div class="flight-validation-errors" id="driverCreateErrors" style="display:none;"></div>
        <label class="flight-modal-label" for="new_driver_full_name">ФИО *</label>
        <input class="flight-modal-input" type="text" id="new_driver_full_name" placeholder="Иванов Иван Иванович">
        <div class="field-inline-hint">Формат: Иванов Иван Иванович</div>
        <div class="field-inline-error" id="new_driver_full_name_error" style="display:none;"></div>
        <label class="flight-modal-label" for="new_driver_vehicle_number">Госномер *</label>
        <input class="flight-modal-input" type="text" id="new_driver_vehicle_number" placeholder="А123АА45" maxlength="9">
        <div class="field-inline-hint">Формат: А123АА45 или А123АА456</div>
        <div class="field-inline-error" id="new_driver_vehicle_number_error" style="display:none;"></div>
        <label class="flight-modal-label" for="new_driver_gps_type">Тип GPS подключения *</label>
        <select class="flight-modal-input" id="new_driver_gps_type">
            <option value="new_tracker">Новый трекер</option>
            <option value="retranslation">Ретрансляция</option>
        </select>
        <div class="driver-gps-note" id="new_driver_gps_note">Система автоматически выберет первый свободный трекер SLITEX, у которого имя состоит только из цифр, и переименует его.</div>
        <div id="new_driver_retranslation_wrap" style="display:none;">
            <label class="flight-modal-label" for="new_driver_tracker_id">ID текущего трекера</label>
            <input class="flight-modal-input" type="text" id="new_driver_tracker_id" placeholder="425252">
            <div class="field-inline-hint">ID трекера берётся из текущего оборудования машины. Его должен сообщить администратор/владелец машины.</div>
            <div class="field-inline-hint">Можно оставить пустым. ID трекера может быть добавлен позже, когда его сообщит администратор машины.</div>
            <div class="field-inline-error" id="new_driver_tracker_id_error" style="display:none;"></div>
        </div>
        <div class="driver-check-wrap" id="new_driver_check_wrap">
            <div class="field-inline-hint">Система может проверить свободные SLITEX-трекеры без изменения данных.</div>
            <button class="route-action-btn route-edit-btn route-action-main" id="driverCheckFreeBtn">Проверить свободные трекеры</button>
            <div class="driver-check-result" id="driverCheckResult" style="display:none;"></div>
            <div class="field-inline-hint">Переименование трекера выполняется только при allow_patch_rename=1.</div>
        </div>
        <div class="driver-copy-wrap" id="new_driver_copy_wrap" style="display:none;">
            <label class="flight-modal-label" for="new_driver_copy_text">Текст для отправки администратору</label>
            <textarea class="flight-modal-input" id="new_driver_copy_text" rows="4" readonly></textarea>
            <div class="flight-modal-actions driver-copy-actions">
                <button class="route-action-btn route-edit-btn route-action-main" id="driverCopyTextBtn">Скопировать текст</button>
                <button class="route-action-btn route-transfer-btn route-action-main" id="driverSendMaxBtn">Отправить в MAX</button>
            </div>
        </div>
        <div class="driver-create-result" id="driverCreateResult" style="display:none;"></div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-transfer-start-btn route-action-main" id="driverCreateSaveBtn">Создать водителя</button>
            <button class="route-action-btn route-action-main" id="driverCreateCancelBtn">Отмена</button>
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
