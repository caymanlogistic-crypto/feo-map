<?php include 'menu2.php'; ?>
<div id="map"></div>

<button class="refresh-btn" id="refreshBtn" title="РћР±РЅРѕРІРёС‚СЊ РїРѕР·РёС†РёРё С‚СЂР°РЅСЃРїРѕСЂС‚Р°">РћР±РЅРѕРІРёС‚СЊ С‚СЂР°РЅСЃРїРѕСЂС‚</button>

<div class="routes-panel">
    <div class="routes-section routes-section-planned">
        <h3 class="routes-title-planned">РџР»Р°РЅРёСЂСѓРµРјС‹Рµ РјР°СЂС€СЂСѓС‚С‹</h3>
        <div id="plannedRoutesList" class="routes-list"><div class="route-list-empty">Р—Р°РіСЂСѓР·РєР°...</div></div>
    </div>
    <div class="routes-section routes-section-found">
        <h3 class="routes-title-found">РЎС„РѕСЂРјРёСЂРѕРІР°РЅРЅС‹Рµ СЂРµР№СЃС‹</h3>
        <div id="foundRoutesList" class="routes-list"><div class="route-list-empty">Р—Р°РіСЂСѓР·РєР°...</div></div>
    </div>
    <div class="routes-section routes-section-started">
        <h3 class="routes-title-started">Р’С‹РІРѕР· РЅР°С‡Р°Р»СЃСЏ</h3>
        <div id="startedRoutesList" class="routes-list"><div class="route-list-empty">Р—Р°РіСЂСѓР·РєР°...</div></div>
    </div>
</div>

<div class="layer-panel">
    <div class="layer-group manager-scope-group">
        <div class="layer-group-title">РњРµРЅРµРґР¶РµСЂ</div>
        <select id="managerScopeSelect" class="manager-scope-select">
            <option value="">РџРѕРєР°Р·Р°С‚СЊ РІСЃРµ</option>
        </select>
        <div class="manager-scope-error" id="managerScopeError" style="display:none;"></div>
    </div>
    <h3>РЈРїСЂР°РІР»РµРЅРёРµ СЃР»РѕСЏРјРё</h3>
    <div class="layer-group">
        <div class="layer-group-title">Р РµР№СЃС‹</div>
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
    <div class="layer-group"><div class="layer-group-title">Р”РѕРїРѕР»РЅРёС‚РµР»СЊРЅРѕ</div>
        <?php foreach ($customLayers as $ln => $ld): ?>
        <div class="checkbox-item"><input type="checkbox" id="custom_layer_<?= md5($ln) ?>" checked onchange="filterByCustomLayer('<?= addslashes($ln) ?>', this.checked)"><label for="custom_layer_<?= md5($ln) ?>"><?= htmlspecialchars($ln) ?></label><div class="color-indicator" style="background: <?= $ld['color'] ?>"></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="layer-group" style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 5px;">
        <div class="layer-group-title">РўСЂР°РЅСЃРїРѕСЂС‚ (Slitex)</div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_active" value="active" checked onchange="setTransportDisplayMode('active')">
            <label for="transport_mode_active">РўСЂР°РЅСЃРїРѕСЂС‚ РІ Р°РєС‚РёРІРЅС‹С… СЂРµР№СЃР°С…</label>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_all" value="all" onchange="setTransportDisplayMode('all')">
            <label for="transport_mode_all">РџРѕРєР°Р·Р°С‚СЊ РІРµСЃСЊ С‚СЂР°РЅСЃРїРѕСЂС‚</label>
        </div>
        <div class="checkbox-item">
            <input type="radio" name="transportMode" id="transport_mode_none" value="none" onchange="setTransportDisplayMode('none')">
            <label for="transport_mode_none">РќРµ РѕС‚РѕР±СЂР°Р¶Р°С‚СЊ С‚СЂР°РЅСЃРїРѕСЂС‚</label>
        </div>
    </div>

    <div class="layer-group" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;"><div class="checkbox-item" style="background: #fff3cd; border: 1px solid #ffc107;"><input type="checkbox" id="disable-popups" checked onchange="togglePopups(this.checked)"><label for="disable-popups">РќРµ РїРѕРєР°Р·С‹РІР°С‚СЊ РїРѕРїР°РїС‹</label></div></div>
</div>

<div class="selection-panel" id="selection-panel">
    <h4>Р’С‹РґРµР»РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё</h4>
    <div id="stats-box">Р—Р°СЏРІРѕРє: 0 вЂў РђРґСЂРµСЃРѕРІ: 0</div>
    <div id="total-weight">РћР±С‰РёР№ РІРµСЃ: 0 РєРі</div>
    <div id="route-edit-status"><span style="color:#28a745;">РЎРѕР·РґР°РЅРёРµ РЅРѕРІРѕРіРѕ РјР°СЂС€СЂСѓС‚Р°</span></div>
    <input type="hidden" id="route-cost-input" value="">
    <div id="route-info-container"></div>
    <div id="selected-list"></div>
    <div class="hint">Р’РІРµРґРёС‚Рµ ID С‡РµСЂРµР· Р·Р°РїСЏС‚СѓСЋ РёР»Рё Enter</div>
    <textarea id="route-input" class="manual-input" placeholder="ID Р·Р°СЏРІРѕРє..."></textarea>
    <div class="route-buttons">
        <button class="route-btn calculate-route-btn" id="calc-route-btn" onclick="calculateRoute()" disabled>Р Р°СЃСЃС‡РёС‚Р°С‚СЊ</button>
        <button class="route-btn save-route-btn" id="saveRouteBtn" onclick="promptSaveRoute()" disabled>РЎРѕС…СЂР°РЅРёС‚СЊ РјР°СЂС€СЂСѓС‚</button>
    </div>
    <button class="clear-btn" onclick="clearSelection()">Р—Р°РєСЂС‹С‚СЊ</button>
</div>

<div class="flight-modal-backdrop" id="flightEditModal" style="display:none;">
    <div class="flight-modal">
        <div class="flight-modal-header">
            <div class="flight-modal-title" id="flightEditTitle">Р РµРґР°РєС‚РёСЂРѕРІР°РЅРёРµ СЂРµР№СЃР°</div>
            <button class="route-action-btn route-icon-btn" id="flightEditCloseTopBtn">вњ•</button>
        </div>

        <input type="hidden" id="edit_flight_id" value="">
        <input type="hidden" id="edit_flight_source" value="">
        <input type="hidden" id="edit_current_status" value="">

        <div class="flight-summary" id="flightLiveSummary"></div>

        <div class="flight-modal-grid">
            <div>
                <label class="flight-modal-label" for="edit_comment">РљРѕРјРјРµРЅС‚Р°СЂРёР№ / Р·Р°РіРѕР»РѕРІРѕРє</label>
                <input class="flight-modal-input" type="text" id="edit_comment">
            </div>
            <div>
                <label class="flight-modal-label" for="edit_driver_id">Р’РѕРґРёС‚РµР»СЊ / РјР°С€РёРЅР°</label>
                <select class="flight-modal-input" id="edit_driver_id"></select>
            </div>
            <div class="flight-date-range" id="plannedDateRangeWrap">
                <label class="flight-modal-label" id="plannedDateRangeTitle" for="edit_planned_start_date_from">Р’С‹РІРѕР· Р·Р°РїР»Р°РЅРёСЂРѕРІР°РЅ РЅР° РґР°С‚С‹</label>
                <div class="flight-date-range-row">
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_planned_start_date_from">РЎ</label>
                        <input class="flight-modal-input" type="date" id="edit_planned_start_date_from">
                    </div>
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_planned_start_date_to">РџРѕ</label>
                        <input class="flight-modal-input" type="date" id="edit_planned_start_date_to">
                    </div>
                </div>
            </div>
            <div class="flight-date-range" id="actualDateRangeWrap" style="display:none;">
                <label class="flight-modal-label" id="actualDateRangeTitle" for="edit_actual_start_date">Р¤Р°РєС‚РёС‡РµСЃРєРёРµ РґР°С‚С‹ РїРµСЂРµРІРѕР·РєРё</label>
                <div class="flight-date-range-row">
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_actual_start_date">РЎ</label>
                        <input class="flight-modal-input" type="date" id="edit_actual_start_date">
                    </div>
                    <div class="flight-date-col">
                        <label class="flight-modal-label flight-sub-label" for="edit_actual_end_date">РџРѕ</label>
                        <input class="flight-modal-input" type="date" id="edit_actual_end_date">
                    </div>
                </div>
            </div>
            <div class="flight-cost-wrap">
                <label class="flight-modal-label" for="edit_cost">РЎС‚РѕРёРјРѕСЃС‚СЊ</label>
                <input class="flight-modal-input" type="number" id="edit_cost" step="0.01" min="0">
            </div>
            <div class="flight-zayavki-wrap">
                <label class="flight-modal-label" for="edit_zayavki_ids">РЎРїРёСЃРѕРє Р·Р°СЏРІРѕРє</label>
                <textarea class="flight-modal-input" id="edit_zayavki_ids" rows="3" placeholder="101,104,105"></textarea>
            </div>
            <div class="flight-checkbox-wrap">
                <label class="flight-modal-checkbox">
                    <input type="checkbox" id="edit_unload_type">
                    <span id="edit_unload_type_label">Р’С‹РІРѕР· РЅР° РІСЂРµРјРµРЅРЅС‹Р№ СЃРєР»Р°Рґ</span>
                </label>
            </div>
        </div>

        <div class="flight-change-preview" id="flightChangePreview" style="display:none;"></div>
        <div class="flight-validation-errors" id="flightValidationErrors" style="display:none;"></div>

        <div class="workflow-section workflow-card workflow-card-update" id="workflowUpdateSection">
            <div class="workflow-title" id="workflowUpdateTitle">РђРєС‚СѓР°Р»РёР·Р°С†РёСЏ СЂРµР№СЃР°</div>
            <div class="workflow-desc" id="workflowUpdateDesc">РР·РјРµРЅРµРЅРёСЏ РґР°С‚ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё С„РёРєСЃРёСЂСѓСЋС‚СЃСЏ РІ РњРђРљРЎ.</div>
            <button class="route-action-btn route-edit-btn route-action-main" id="flightEditSaveBtn">РЎРѕС…СЂР°РЅРёС‚СЊ/РћР±РЅРѕРІРёС‚СЊ</button>
        </div>

        <div class="workflow-section workflow-card workflow-card-lifecycle" id="workflowLifecycleSection">

            <div class="workflow-action workflow-action-info" id="workflowStartedInfoWrap">
                <div class="workflow-subtitle">Р РµР№СЃ РІС‹РїРѕР»РЅСЏРµС‚СЃСЏ</div>
                <div class="workflow-desc">РљРѕРЅС‚СЂРѕР»СЊ РїРµСЂРµРІРѕР·РєРё Р°РєС‚РёРІРµРЅ. РР·РјРµРЅРµРЅРёРµ РїР°СЂР°РјРµС‚СЂРѕРІ СЂРµР№СЃР° РЅРµРґРѕСЃС‚СѓРїРЅРѕ.</div>
            </div>

            <div class="workflow-action workflow-action-primary workflow-action-found" id="workflowToFoundWrap">
                <div class="workflow-subtitle">РЎС„РѕСЂРјРёСЂРѕРІР°С‚СЊ СЂРµР№СЃ</div>
                <div class="workflow-desc">РџРѕСЃР»Рµ РїРµСЂРµРІРѕРґР° СЂРµР№СЃ СЃС‡РёС‚Р°РµС‚СЃСЏ СЃРѕРіР»Р°СЃРѕРІР°РЅРЅС‹Рј. Р‘СѓРґСѓС‚ Р·Р°С„РёРєСЃРёСЂРѕРІР°РЅС‹ РІРѕРґРёС‚РµР»СЊ, РґР°С‚С‹, СЃС‚РѕРёРјРѕСЃС‚СЊ Рё Р·Р°СЏРІРєРё. Р’ MAX РѕС‚РїСЂР°РІРёС‚СЃСЏ СѓРІРµРґРѕРјР»РµРЅРёРµ, РЅР°С‡РЅС‘С‚СЃСЏ РїРѕРґРіРѕС‚РѕРІРєР° С‚СЂР°РЅСЃРїРѕСЂС‚РЅС‹С… РґРѕРєСѓРјРµРЅС‚РѕРІ.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditTransferFoundBtn">РЎС„РѕСЂРјРёСЂРѕРІР°С‚СЊ СЂРµР№СЃ</button>
            </div>

            <div class="workflow-action workflow-action-primary workflow-action-started" id="workflowToStartedWrap">
                <div class="workflow-subtitle">РќР°С‡Р°Р»Рѕ РІС‹РїРѕР»РЅРµРЅРёСЏ</div>
                <div class="workflow-desc">Р РµР№СЃ РїРµСЂРµР№РґС‘С‚ РІ СЃС‚Р°С‚СѓСЃ В«Р’С‹РІРѕР· РЅР°С‡Р°Р»СЃСЏВ». РџРѕРґРєР»СЋС‡Р°РµС‚СЃСЏ РєРѕРЅС‚СЂРѕР»СЊ РІС‹РїРѕР»РЅРµРЅРёСЏ РїРµСЂРµРІРѕР·РєРё Рё Р»РѕРіРёРєР° С‚СЂРµРєРµСЂР°. Р’ MAX Р±СѓРґРµС‚ РѕС‚РїСЂР°РІР»РµРЅРѕ СѓРІРµРґРѕРјР»РµРЅРёРµ.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditTransferStartedBtn">РџРѕРґС‚РІРµСЂРґРёС‚СЊ РЅР°С‡Р°Р»Рѕ РІС‹РІРѕР·Р°</button>
            </div>

            <div class="workflow-action workflow-action-secondary workflow-action-return" id="workflowBackToPlannedWrap">
                <div class="workflow-subtitle">Р’РѕР·РІСЂР°С‚ РІ РїР»Р°РЅРёСЂРѕРІР°РЅРёРµ</div>
                <div class="workflow-desc">Р РµР№СЃ Р±СѓРґРµС‚ РІРѕР·РІСЂР°С‰С‘РЅ РІ РїР»Р°РЅРёСЂРѕРІР°РЅРёРµ. РџРѕРґРіРѕС‚РѕРІРєСѓ РґРѕРєСѓРјРµРЅС‚РѕРІ РЅСѓР¶РЅРѕ РїСЂРѕРІРµСЂРёС‚СЊ РёР»Рё РїСЂРёРѕСЃС‚Р°РЅРѕРІРёС‚СЊ. Р’ MAX Р±СѓРґРµС‚ РѕС‚РїСЂР°РІР»РµРЅРѕ СѓРІРµРґРѕРјР»РµРЅРёРµ.</div>
                <button class="route-action-btn route-transfer-btn route-action-main" id="flightEditBackToPlannedBtn">Р’РµСЂРЅСѓС‚СЊ РІ РїР»Р°РЅРёСЂСѓРµРјС‹Рµ</button>
            </div>

            <div class="workflow-action workflow-action-secondary workflow-action-found" id="workflowBackToFoundWrap">
                <div class="workflow-subtitle">РџСЂРёРѕСЃС‚Р°РЅРѕРІРёС‚СЊ РІС‹РїРѕР»РЅРµРЅРёРµ</div>
                <div class="workflow-desc">Рейс будет возвращён из выполнения в статус «Рейс сформирован». В MAX будет отправлено уведомление.</div>
            </div>
            <div class="workflow-action workflow-action-primary workflow-action-completed" id="workflowToCompletedWrap">
                <div class="workflow-subtitle">Р—Р°РІРµСЂС€РµРЅРёРµ СЂРµР№СЃР°</div>
                <div class="workflow-desc">РџРѕСЃР»Рµ Р·Р°РІРµСЂС€РµРЅРёСЏ СЂРµР№СЃ Р±СѓРґРµС‚ РїРµСЂРµРІРµРґРµРЅ РІ Р°СЂС…РёРІ РїРµСЂРµРІРѕР·РѕРє.</div>
                <button class="route-action-btn route-transfer-start-btn route-action-main" id="flightEditToCompletedBtn">Р—Р°РІРµСЂС€РёС‚СЊ СЂРµР№СЃ</button>
            </div>
        </div>

        <div class="danger-zone workflow-card workflow-card-danger" id="workflowDeleteWrap">
            <div class="workflow-title">РЈРґР°Р»РµРЅРёРµ РјР°СЂС€СЂСѓС‚Р°</div>
            <div class="workflow-desc">РњР°СЂС€СЂСѓС‚ Р±СѓРґРµС‚ СѓРґР°Р»С‘РЅ РёР· СЃРїРёСЃРєР° РїР»Р°РЅРёСЂСѓРµРјС‹С… СЂРµР№СЃРѕРІ.</div>
            <button class="route-action-btn route-manage-danger route-action-main" id="flightEditDeleteBtn">РЈРґР°Р»РёС‚СЊ РјР°СЂС€СЂСѓС‚</button>
        </div>

        <div class="flight-modal-footer">
            <button class="route-action-btn route-action-main" id="flightEditCancelBtn">Р—Р°РєСЂС‹С‚СЊ</button>
        </div>
    </div>
</div>

<div class="flight-modal-backdrop" id="startConfirmModal" style="display:none;">
    <div class="flight-modal flight-modal-confirm">
        <div class="flight-modal-title" id="transitionConfirmTitle">РџРѕРґС‚РІРµСЂР¶РґРµРЅРёРµ РґРµР№СЃС‚РІРёСЏ</div>
        <input type="hidden" id="start_flight_id" value="">
        <input type="hidden" id="start_target_status" value="">
        <label class="flight-modal-label" id="transitionConfirmDateLabel" for="start_actual_start_date">Р”Р°С‚Р° РЅР°С‡Р°Р»Р° РІС‹РІРѕР·Р°</label>
        <input class="flight-modal-input" type="date" id="start_actual_start_date">
        <div class="flight-change-preview" id="transitionConfirmPreview"></div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-transfer-start-btn route-action-main" id="startConfirmBtn">РџРѕРґС‚РІРµСЂРґРёС‚СЊ</button>
            <button class="route-action-btn route-action-main" id="startCancelBtn">РћС‚РјРµРЅР°</button>
        </div>
    </div>
</div>
