<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

respond([
    "success" => true,
    "whatsapp_support" => BOOKKAM_WHATSAPP_SUPPORT,
]);
