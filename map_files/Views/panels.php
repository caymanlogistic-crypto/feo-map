
<?php include 'menu2.php'; ?>
<div id="map"></div>

<!-- РљРЅРѕРїРєР° РѕР±РЅРѕРІР»РµРЅРёСЏ С‚СЂРµРєРµСЂРѕРІ -->
<button class="refresh-btn" id="refreshBtn" title="РћР±РЅРѕРІРёС‚СЊ РїРѕР·РёС†РёРё С‚СЂР°РЅСЃРїРѕСЂС‚Р°">рџ”„ РћР±РЅРѕРІРёС‚СЊ С‚СЂР°РЅСЃРїРѕСЂС‚</button>

<div class="routes-panel">
    <h3 class="routes-title-planned">рџ—єпёЏ РџР»Р°РЅРёСЂСѓРµРјС‹Рµ РјР°СЂС€СЂСѓС‚С‹</h3>
    <div id="plannedRoutesList" class="routes-list"><div class="route-list-empty">Р—Р°РіСЂСѓР·РєР°...</div></div>
    <h3 class="routes-title-found" style="margin-top: 14px;">вњ… РСЃРїРѕР»РЅРёС‚. РЅР°Р№РґРµРЅ</h3>
    <div id="foundRoutesList" class="routes-list"><div class="route-list-empty">Р—Р°РіСЂСѓР·РєР°...</div></div>
</div>

<div class="layer-panel">
    <h3>РЈРїСЂР°РІР»РµРЅРёРµ СЃР»РѕСЏРјРё</h3>
    <div class="layer-group">
        <div class="layer-group-title">рџљљ Р РµР№СЃС‹</div>
        <div class="checkbox-item"><input type="checkbox" id="no_flight_default" checked onchange="filterByCustomLayer('default', this.checked)"><label for="no_flight_default">Р”РѕСЃС‚СѓРїРЅРѕ Рє РІС‹РІРѕР·Сѓ</label><div class="color-indicator" style="background: #000000"></div></div>
        <?php if (isset($flightStatusList['planned_route'])): ?>
        <div class="checkbox-item"><input type="checkbox" id="flight_status_planned_route" checked onchange="filterByFlightStatus('planned_route', this.checked)"><label for="flight_status_planned_route">РџР»Р°РЅРёСЂСѓРµРјС‹Рµ РјР°СЂС€СЂСѓС‚С‹</label><div class="color-indicator" style="background: #9c27b0"></div></div>
        <?php endif; ?>
        <?php foreach ($flightStatusList as $status => $v): ?>
        <?php if ($status === 'planned_route') continue; ?>
        <div class="checkbox-item"><input type="checkbox" id="flight_status_<?= $status ?>" <?= ($status == 'started' || $status == 'completed') ? '' : 'checked' ?> onchange="filterByFlightStatus('<?= $status ?>', this.checked)"><label for="flight_status_<?= $status ?>"><?= htmlspecialchars($statusNames[$status] ?? $status) ?></label><div class="color-indicator" style="background: <?= $statusColors[$status] ?? '#000000' ?>"></div></div>
        <?php endforeach; ?>
    </div>
    <?php if ($hasDefault || !empty($customLayers)): ?>
    <div class="layer-group"><div class="layer-group-title">рџ“¦ Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕ</div>
        <?php foreach ($customLayers as $ln => $ld): ?>
        <div class="checkbox-item"><input type="checkbox" id="custom_layer_<?= md5($ln) ?>" checked onchange="filterByCustomLayer('<?= addslashes($ln) ?>', this.checked)"><label for="custom_layer_<?= md5($ln) ?>"><?= htmlspecialchars($ln) ?> <span class="custom-badge">РєР°СЃС‚РѕРјРЅС‹Р№</span></label><div class="color-indicator" style="background: <?= $ld['color'] ?>"></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- === РќРћР’Р«Р™ Р¤РР›Р¬РўР : РўР РђРќРЎРџРћР Рў === -->
    <div class="layer-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 5px;">
        <div class="layer-group-title">рџљ› РўСЂР°РЅСЃРїРѕСЂС‚ (Slitex)</div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_active" value="active" checked onchange="setTransportDisplayMode('active')">
            <label for="transport_mode_active">РџРѕРєР°Р·Р°С‚СЊ С‚РѕР»СЊРєРѕ Р°РєС‚РёРІРЅС‹Р№ С‚СЂР°РЅСЃРїРѕСЂС‚</label>
            <div class="color-indicator" style="background: #2196F3"></div>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_all" value="all" onchange="setTransportDisplayMode('all')">
            <label for="transport_mode_all">РџРѕРєР°Р·Р°С‚СЊ РІРµСЃСЊ С‚СЂР°РЅСЃРїРѕСЂС‚</label>
            <div class="color-indicator" style="background: linear-gradient(135deg, #2196F3, #4CAF50, #FF9800, #F44336)"></div>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_none" value="none" onchange="setTransportDisplayMode('none')">
            <label for="transport_mode_none">РќРµ РѕС‚РѕР±СЂР°Р¶Р°С‚СЊ С‚СЂР°РЅСЃРїРѕСЂС‚</label>
            <div class="color-indicator" style="background: #9e9e9e"></div>
        </div>
        <div style="font-size: 10px; color: #888; margin-left: 24px; margin-top: 4px;">
            рџ”µ &lt;10Рј | рџџў &lt;1С‡ | рџџ  &lt;3С‡ | рџ”ґ &gt;3С‡
        </div>
    </div>
    
    <div class="layer-group" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;"><div class="checkbox-item" style="background: #fff3cd; border: 1px solid #ffc107;"><input type="checkbox" id="disable-popups" checked onchange="togglePopups(this.checked)"><label for="disable-popups">рџљ« РќРµ РїРѕРєР°Р·С‹РІР°С‚СЊ РїРѕРїР°РїС‹</label></div></div>
</div>

<div class="selection-panel" id="selection-panel">
    <h4>рџ“ќ Р’С‹РґРµР»РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё</h4>
    <div id="stats-box">Р—Р°СЏРІРѕРє: 0 | РђРґСЂРµСЃРѕРІ: 0</div>
    <div id="total-weight">РћР±С‰РёР№ РІРµСЃ: 0 РєРі</div>
    <div id="route-edit-status"><span style="color:#28a745;">рџ†• РЎРѕР·РґР°РЅРёРµ РЅРѕРІРѕРіРѕ РјР°СЂС€СЂСѓС‚Р°</span></div>
    <div class="cost-input-container">
        <label for="route-cost-input">рџ’° РЎС‚РѕРёРјРѕСЃС‚СЊ РјР°СЂС€СЂСѓС‚Р° (в‚Ѕ):</label>
        <input type="number" id="route-cost-input" class="cost-input" placeholder="0.00" step="0.01" min="0">
    </div>
    <div id="route-info-container"></div>
    <div id="selected-list"></div>
    <div class="hint">Р’РІРѕРґРёС‚Рµ ID С‡РµСЂРµР· Р·Р°РїСЏС‚СѓСЋ РёР»Рё Enter</div>
    <textarea id="route-input" class="manual-input" placeholder="ID Р·Р°СЏРІРѕРє..."></textarea>
    <div class="route-buttons">
        <button class="route-btn calculate-route-btn" id="calc-route-btn" onclick="calculateRoute()" disabled>рџљљ Р Р°СЃСЃС‡РёС‚Р°С‚СЊ</button>
        <button class="route-btn save-route-btn" id="saveRouteBtn" onclick="promptSaveRoute()" disabled>рџ“Њ РЎРѕС…СЂР°РЅРёС‚СЊ РєР°Рє РјР°СЂС€СЂСѓС‚</button>
    </div>
    <button class="clear-btn" onclick="clearSelection()">РћС‡РёСЃС‚РёС‚СЊ РІС‹РґРµР»РµРЅРёРµ</button>
</div>

<div class="flight-modal-backdrop" id="flightEditModal" style="display:none;">
    <div class="flight-modal flight-modal-fullscreen">
        <div class="flight-modal-header">
            <div class="flight-modal-title" id="flightEditTitle">Управление рейсом</div>
            <button class="route-action-btn" id="flightEditCloseTopBtn">✕</button>
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
            <div>
                <label class="flight-modal-label" for="edit_planned_start_date_from">Планируемое с</label>
                <input class="flight-modal-input" type="datetime-local" id="edit_planned_start_date_from">
            </div>
            <div>
                <label class="flight-modal-label" for="edit_planned_start_date_to">Планируемое по</label>
                <input class="flight-modal-input" type="datetime-local" id="edit_planned_start_date_to">
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
        <div class="flight-modal-actions">
            <button class="route-action-btn route-edit-btn" id="flightEditSaveBtn">Сохранить</button>
            <button class="route-action-btn route-transfer-btn" id="flightEditTransferFoundBtn">В ИСПОЛНИТЕЛЬНАЙДЕН</button>
            <button class="route-action-btn route-transfer-start-btn" id="flightEditTransferStartedBtn">В ВЫВОЗНАЧАЛСЯ</button>
            <button class="route-action-btn" id="flightEditBackToPlannedBtn">В ПЛАНИРУЕМЫЙ</button>
            <button class="route-action-btn" id="flightEditBackToFoundBtn">В ИСПОЛНИТЕЛЬНАЙДЕН</button>
            <button class="route-action-btn route-manage-danger" id="flightEditDeleteBtn">Удалить рейс</button>
            <button class="route-action-btn" id="flightEditCancelBtn">Отмена</button>
        </div>
    </div>
</div>

<div class="flight-modal-backdrop" id="startConfirmModal" style="display:none;">
    <div class="flight-modal">
        <div class="flight-modal-title" id="transitionConfirmTitle">Подтверждение действия</div>
        <input type="hidden" id="start_flight_id" value="">
        <input type="hidden" id="start_target_status" value="">
        <label class="flight-modal-label" for="start_actual_start_date">Дата начала вывоза</label>
        <input class="flight-modal-input" type="datetime-local" id="start_actual_start_date">
        <div class="flight-change-preview" id="transitionConfirmPreview"></div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-transfer-start-btn" id="startConfirmBtn">Подтвердить</button>
            <button class="route-action-btn" id="startCancelBtn">Отмена</button>
        </div>
    </div>
</div>