<?php
//------------------------------------------------------------------------------
// backup.php
// Backup Manager Web UI
//------------------------------------------------------------------------------
//includes files
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

//ensure settings object exists when check_auth is bypassed
if (!isset($settings) || !is_object($settings)) {
    $settings = new settings([
        'database' => $database,
        'domain_uuid' => $_SESSION['domain_uuid'] ?? '',
        'user_uuid' => $_SESSION['user_uuid'] ?? ''
    ]);
}

//check permissions
if (!permission_exists('backup_manager_backup')) {
    echo "access denied";
    exit;
}

$settings_file = '/var/backups/fusionpbx/backup_settings.json';
$backup_settings = ['auto_enabled'=>false,'frequency'=>'daily','keep'=>7];
if (file_exists($settings_file)) {
    $json = file_get_contents($settings_file);
    $data = json_decode($json, true);
    if (is_array($data)) $backup_settings = array_merge($backup_settings, $data);
}

$log_file = '/var/log/fusionpbx/backup_manager.log';
if (!file_exists(dirname($log_file))) {
    mkdir(dirname($log_file), 0755, true);
}
$message = '';
if (!empty($_POST['action']) && $_POST['action'] === 'backup') {
    // Path to your backup script
    $script = '/var/www/fusionpbx/app/backup_manager/scripts/fusionpbx-backup-manager.sh';
    // Execute backup (ensure www-data has sudo rights for this script)
    $cmd = 'sudo ' . escapeshellarg($script) . ' 2>&1';
    exec($cmd, $output, $status);
    $log_entry = date('Y-m-d H:i:s') . "\nCMD: $cmd\n" .
        "STATUS: $status\n" .
        implode(PHP_EOL, $output) . "\n\n";
    error_log($log_entry, 3, $log_file);
    if ($status === 0) {
        $message = 'Backup completed successfully.';
    } else {
        $message = 'Backup failed! Check logs.';
        if (!empty($output)) {
            $message .= '<pre>' . htmlspecialchars(implode(PHP_EOL, $output)) . '</pre>';
        }
    }
}

if (!empty($_GET['delete'])) {
    $del = basename($_GET['delete']);
    $path = '/var/backups/fusionpbx/' . $del;
    if (file_exists($path)) {
        if (unlink($path)) {
            $message = 'Backup deleted.';
            if (preg_match('/backup_(\d{8}_\d{6})\.tgz$/', $del, $m)) {
                $sql_path = '/var/backups/fusionpbx/postgresql/fusionpbx_' . $m[1] . '.sql';
                if (file_exists($sql_path)) {
                    if (!unlink($sql_path)) {
                        $err = error_get_last();
                        $error_msg = $err['message'] ?? 'unknown error';
                        error_log("Failed to delete SQL file $sql_path: $error_msg");
                        $message .= ' SQL delete failed: ' . $error_msg;
                    }
                }
            }
        } else {
            $err = error_get_last();
            $error_msg = $err['message'] ?? 'unknown error';
            error_log("Failed to delete backup file $path: $error_msg");
            $message = 'Failed to delete backup. ' . $error_msg;
        }
    }
}

if (!empty($_POST['action']) && $_POST['action'] === 'upload' && !empty($_FILES['backup_file']['tmp_name'])) {
    $upload_name = basename($_FILES['backup_file']['name']);
    if (preg_match('/\.tgz$/', $upload_name)) {
        $target = '/var/backups/fusionpbx/' . $upload_name;
        if (file_exists($target)) {
            $message = 'File already exists.';
        }
        else {
            if (move_uploaded_file($_FILES['backup_file']['tmp_name'], $target)) {
                $message = 'Backup uploaded.';
            }
            else {
                $err = error_get_last();
                $error_msg = $err['message'] ?? 'unknown error';
                $message = 'Upload failed: ' . $error_msg;
            }
        }
    }
    else {
        $message = 'Invalid file type.';
    }
}

if (isset($_POST['save_settings'])) {
    $backup_settings['auto_enabled'] = isset($_POST['auto_enabled']);
    $backup_settings['frequency'] = $_POST['frequency'] ?? 'daily';
    $backup_settings['keep'] = (int)($_POST['keep'] ?? 7);
    file_put_contents($settings_file, json_encode($backup_settings));
    $message = 'Settings saved';
}

//create csrf token
$object = new token;
$token = $object->create('/app/backup_manager/backup.php');

//include the header
$document['title'] = 'Backup Manager';
require_once "resources/header.php";

//page heading and actions
echo "<div class='action_bar' id='action_bar'>\n";
echo "  <div class='heading'><b>Backup Manager</b></div>\n";
echo "  <div class='actions'>\n";
echo "      <form id='form_backup' class='inline' method='post'>\n";
echo "          <input type='hidden' name='action' value='backup'>\n";
echo "          <input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
echo button::create(['type'=>'submit','label'=>'Run Backup','icon'=>$settings->get('theme', 'button_icon_play'),'id'=>'btn_backup']);
echo "      </form>\n";
echo "      <form id='form_upload' class='inline' method='post' enctype='multipart/form-data'>\n";
echo "          <input type='hidden' name='action' value='upload'>\n";
echo "          <input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
echo button::create(['type'=>'button','label'=>'Upload','icon'=>$settings->get('theme','button_icon_add'),'id'=>'btn_upload','style'=>'margin-left: 15px;','onclick'=>"$(this).fadeOut(250, function(){ $('span#form_upload').fadeIn(250); document.getElementById('ulfile').click(); });"]);
echo "          <span id='form_upload' style='display: none;'>";
echo button::create(['label'=>'Cancel','icon'=>$settings->get('theme','button_icon_cancel'),'type'=>'button','id'=>'btn_upload_cancel','style'=>'margin-left: 15px;','onclick'=>"$('span#form_upload').fadeOut(250, function(){ document.getElementById('form_upload').reset(); $('#btn_upload').fadeIn(250) });"]);
echo "              <input type='text' class='txt' style='width: 100px; cursor: pointer;' id='filename' placeholder='Select...' onclick=\"document.getElementById('ulfile').click(); this.blur();\" onfocus='this.blur();'>";
echo "              <input type='file' id='ulfile' name='backup_file' style='display: none;' accept='.tgz' onchange=\"document.getElementById('filename').value = this.files.item(0).name;\">";
echo button::create(['type'=>'submit','label'=>'Upload','icon'=>$settings->get('theme','button_icon_upload')]);
echo "          </span>\n";
echo "      </form>\n";
echo "  </div>\n";
echo "  <div style='clear: both;'></div>\n";
echo "</div>\n";

if ($message) {
    echo "<div class='message'>$message</div>";
}

// settings form
echo "<form method='post' style='margin-bottom:20px;'>";
echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
echo "<h3>Auto Backup Settings</h3>";
echo "<label><input type='checkbox' name='auto_enabled'".($backup_settings['auto_enabled']?' checked':'')."> Enable Auto Backup</label><br>";
echo "<label>Frequency:</label>";
echo "<select name='frequency'>";
foreach (['daily','weekly','monthly'] as $freq) {
    $sel = $backup_settings['frequency']==$freq ? 'selected' : '';
    echo "<option value='$freq' $sel>$freq</option>";
}
echo "</select><br>";
echo "<label>Keep Backups:</label> <input type='number' name='keep' value='".intval($backup_settings['keep'])."' min='1' style='width:60px;'>";
echo "<br>";
echo button::create(['type'=>'submit','label'=>'Save Settings','icon'=>$settings->get('theme','button_icon_save'),'name'=>'save_settings','id'=>'btn_save']);
echo "</form>";

// manual backup button moved to action bar

// List existing backups
$dir = '/var/backups/fusionpbx';
$files = array_filter(scandir($dir, SCANDIR_SORT_DESCENDING), function($f) {
    return preg_match('/\.tgz$/', $f);
});
if (!empty($files)) {
    echo '<h3>Available Backups</h3>';
    echo "<div class='card'>";
    echo "<table class='list'>";
    echo "<tr class='list-header'>";
    echo "  <th>Filename</th>";
    echo "  <th>Size</th>";
    echo "  <th>Date</th>";
    echo "  <th class='center'>Actions</th>";
    echo "</tr>";
    foreach (array_slice($files, 0, 10) as $file) {
        $path = $dir . '/' . $file;
        $size = round(filesize($path) / 1024 / 1024, 2) . ' MB';
        $date = date('Y-m-d H:i:s', filemtime($path));
        $url_download  = '/app/backup_manager/download.php?file=' . urlencode($file);
        $url_restore   = '/app/backup_manager/restore.php?file=' . urlencode($file);
        $url_delete    = '/app/backup_manager/backup.php?delete=' . urlencode($file);
        echo "<tr class='list-row'>";
        echo "  <td>".escape($file)."</td>";
        echo "  <td>".escape($size)."</td>";
        echo "  <td>".escape($date)."</td>";
        echo "  <td class='no-link center'>";
        echo button::create(['type'=>'button','label'=>'Restore','icon'=>$settings->get('theme','button_icon_refresh'),'link'=>$url_restore]);
        echo button::create(['type'=>'button','label'=>'Download','icon'=>$settings->get('theme','button_icon_download'),'link'=>$url_download]);
        echo button::create(['type'=>'button','label'=>'Delete','icon'=>$settings->get('theme','button_icon_delete'),'link'=>$url_delete,'onclick'=>"return confirm('Delete?');"]);
        echo "  </td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";
}

require_once "resources/footer.php";
?>
