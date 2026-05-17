<?php
$jsPath = __DIR__ . '/../assets/maptest.js';
$jsVersion = @filemtime($jsPath);
if ($jsVersion === false) {
    $jsVersion = time();
}
?>
<script>
window.MAP_BOOTSTRAP = {
    initialTrackers: <?= $trackersJson ?>,
    allTrackers: <?= $allTrackersJson ?>,
    routeCardsMeta: <?= $routeCardsMetaJson ?>,
    foundRoutesData: <?= $foundRoutesJson ?>,
    driversForSelect: <?= $driversForSelectJson ?>,
    routeCardsBuildError: <?= json_encode($routeCardsBuildError, JSON_UNESCAPED_UNICODE) ?>,
    statusNames: <?= json_encode($statusNames, JSON_UNESCAPED_UNICODE) ?>,
    groupsData: <?= json_encode($processedGroups, JSON_UNESCAPED_UNICODE) ?>,
    flightStatusList: <?= json_encode($flightStatusList, JSON_UNESCAPED_UNICODE) ?>,
    customLayers: <?= json_encode($customLayers, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="map_files/assets/maptest.js?v=<?= (int)$jsVersion ?>"></script>
