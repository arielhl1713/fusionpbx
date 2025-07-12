<?php
//------------------------------------------------------------------------------
// restore.php
// Restore Manager Web UI
//------------------------------------------------------------------------------
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//check permissions
if (!permission_exists('backup_manager_restore')) {
    echo "access denied";
    exit;
}

$message = '';
$selected_file = $_GET['file'] ?? '';
if (!empty($_POST['action']) && $_POST['action'] === 'restore') {
    $backup_file = escapeshellarg('/var/backups/fusionpbx/' . $_POST['backup_file']);
    $option      = $_POST['restore_option'] ?? 'full';
    // Pre-restore safety dump
    $pre = '/var/www/fusionpbx/app/backup_manager/scripts/fusionpbx-pre-restore.sh';
    $pre_output = [];
    exec('sudo ' . escapeshellarg($pre) . ' 2>&1', $pre_output, $pre_status);
    // Extract and restore based on option
    $script = '/var/www/fusionpbx/app/backup_manager/scripts/fusionpbx-restore-manager.sh';
    $cmd = 'sudo ' . escapeshellarg($script) . ' ' . $backup_file . ' ' . escapeshellarg($option) . ' 2>&1';
    $output = [];
    exec($cmd, $output, $status);
    $message = $status === 0 ? 'Restore completed successfully.' : 'Restore failed!';
    $output = array_merge($pre_output, $output);
}

//create csrf token
$object = new token;
$token = $object->create('/app/backup_manager/restore.php');

//include the header
$document['title'] = 'Restore Manager';
require_once "resources/header.php";

//form start and action bar
echo "<form id='form_restore' method='post'>";
echo "<input type='hidden' name='action' value='restore'>";
echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
echo "<div class='action_bar' id='action_bar'>\n";
echo "  <div class='heading'><b>Restore Manager</b></div>\n";
echo "  <div class='actions'>\n";
echo button::create(['type'=>'submit','label'=>'Run Restore','icon'=>$settings->get('theme','button_icon_play'),'id'=>'btn_restore']);
echo "  </div>\n";
echo "  <div style='clear: both;'></div>\n";
echo "</div>\n";

if ($message) {
    echo "<div class='message'>$message</div>";
    if (!empty($output)) {
        echo '<pre>' . htmlspecialchars(implode(PHP_EOL, $output)) . '</pre>';
    }
}

echo '<label>Select Backup File:</label><br/>';
echo '<select name="backup_file">';
foreach (array_filter(scandir('/var/backups/fusionpbx', SCANDIR_SORT_DESCENDING), function($f){return preg_match('/\.tgz$/', $f);} ) as $file) {
    $sel = $selected_file === $file ? 'selected' : '';
    echo '<option value="' . htmlspecialchars($file) . '" ' . $sel . '>' . htmlspecialchars($file) . '</option>';
}
echo '</select><br/><br/>';

echo '<label>Restore Type:</label><br/>';
echo '<label><input type="radio" name="restore_option" value="full" checked /> Full Backup</label><br/>';
echo '<label><input type="radio" name="restore_option" value="media" /> Recordings, Music & Storage Only</label><br/><br/>';

echo '</form>';

require_once "resources/footer.php";
?>
