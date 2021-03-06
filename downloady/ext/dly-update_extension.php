<?php
/*
    dly-update_extension.php
    
    Copyright (c) 2015 - 2017 Andreas Schmidhuber
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this
       list of conditions and the following disclaimer.
    2. Redistributions in binary form must reproduce the above copyright notice,
       this list of conditions and the following disclaimer in the documentation
       and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
    DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
    ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
    ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
    (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
    SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

    The views and conclusions contained in the software and documentation are those
    of the authors and should not be interpreted as representing official policies,
    either expressed or implied, of the FreeBSD Project.
 */
require("auth.inc");
require("guiconfig.inc");

bindtextdomain("nas4free", "/usr/local/share/locale-dly");
$pgtitle = array(gettext("Extensions"), gettext("Downloady")." ".$config['downloady']['version'], gettext("Maintenance"));

if (is_file("{$config['downloady']['rootfolder']}log/oneload")) { require_once("{$config['downloady']['rootfolder']}log/oneload"); }

$return_val = mwexec("fetch -o {$config['downloady']['rootfolder']}version_server.txt https://raw.github.com/crestAT/nas4free-downloady/master/downloady/version.txt", false);
if ($return_val == 0) {
    $server_version = exec("cat {$config['downloady']['rootfolder']}version_server.txt");
    if ($server_version != $config['downloady']['version']) { $savemsg = sprintf(gettext("New extension version %s available, push '%s' button to install the new version!"), $server_version, gettext("Update Extension")); }
    mwexec("fetch -o {$config['downloady']['rootfolder']}release_notes.txt https://raw.github.com/crestAT/nas4free-downloady/master/downloady/release_notes.txt", false);
}
else { $server_version = gettext("Unable to retrieve version from server!"); }

function cronjob_process_updatenotification($mode, $data) {
	global $config;
	$retval = 0;
	switch ($mode) {
		case UPDATENOTIFY_MODE_NEW:
		case UPDATENOTIFY_MODE_MODIFIED:
			break;
		case UPDATENOTIFY_MODE_DIRTY:
			if (is_array($config['cron']) && is_array($config['cron']['job'])) {
				$index = array_search_ex($data, $config['cron']['job'], "uuid");
				if (false !== $index) {
					unset($config['cron']['job'][$index]);
					write_config();
				}
			}
			break;
	}
	return $retval;
}

if (isset($_POST['ext_remove']) && $_POST['ext_remove']) {
// remove start/stop commands
// remove existing old rc format entries
	if (is_array($config['rc']) && is_array($config['rc']['postinit']) && is_array( $config['rc']['postinit']['cmd'])) {
	    $rc_param_count = count($config['rc']['postinit']['cmd']);
	    for ($i = 0; $i < $rc_param_count; ++$i) {
	        if (preg_match('/downloady/', $config['rc']['postinit']['cmd'][$i])) unset($config['rc']['postinit']['cmd'][$i]);
	    }
	}
	if (is_array($config['rc']) && is_array($config['rc']['shutdown']) && is_array( $config['rc']['shutdown']['cmd'])) {
	    $rc_param_count = count($config['rc']['shutdown']['cmd']);
	    for ($i = 0; $i < $rc_param_count; ++$i) {
	        if (preg_match('/downloady/', $config['rc']['shutdown']['cmd'][$i])) unset($config['rc']['shutdown']['cmd'][$i]);
	    }
	}
	// remove existing entries for new rc format
	if (is_array($config['rc']) && is_array($config['rc']['param'])) {
		$rc_param_count = count($config['rc']['param']);
	    for ($i = 0; $i < $rc_param_count; $i++) {
	        if (preg_match('/downloady/', $config['rc']['param'][$i]['value'])) unset($config['rc']['param'][$i]);
		}
	}
// remove cronjobs
    if (isset($config['downloady']['enable_schedule'])) {
    	updatenotify_set("cronjob", UPDATENOTIFY_MODE_DIRTY, $config['downloady']['schedule_uuid_startup']);
		if (is_array($config['cron']) && is_array($config['cron']['job'])) {
			$index = array_search_ex($data, $config['cron']['job'], "uuid");
			if (false !== $index) {
				unset($config['cron']['job'][$index]);
			}
		}
    	updatenotify_set("cronjob", UPDATENOTIFY_MODE_DIRTY, $config['downloady']['schedule_uuid_closedown']);
		if (is_array($config['cron']) && is_array($config['cron']['job'])) {
			$index = array_search_ex($data, $config['cron']['job'], "uuid");
			if (false !== $index) {
				unset($config['cron']['job'][$index]);
			}
		}
    	write_config();
        $retval = 0;
        if (!file_exists($d_sysrebootreqd_path)) {
        	$retval |= updatenotify_process("cronjob", "cronjob_process_updatenotification");
        	config_lock();
        	$retval |= rc_update_service("cron");
        	config_unlock();
        }
        $savemsg = get_std_save_message($retval);
        if ($retval == 0) {
        	updatenotify_delete("cronjob");
        }
    }
// remove extension pages
	mwexec ("rm -rf /usr/local/www/ext/downloady");
	mwexec ("rm -rf /usr/local/www/downloady.php");
	mwexec ("rm -rf /usr/local/www/dly-*");
    mwexec("rmdir -p /usr/local/www/ext");    // to prevent empty extensions menu entry in top GUI menu if there are no other extensions installed
// unlink created links
    if (is_link("/usr/local/share/locale-dly")) unlink("/usr/local/share/locale-dly");
// remove application section from config.xml
	if ( is_array($config['downloady'] ) ) { unset( $config['downloady'] ); write_config();}
	header("Location:index.php");
}

if (isset($_POST['ext_update']) && $_POST['ext_update']) {
// download installer & install
    $return_val = mwexec("fetch -vo {$config['downloady']['rootfolder']}downloady-install.php 'https://raw.github.com/crestAT/nas4free-downloady/master/downloady/downloady-install.php'", true);
    if ($return_val == 0) {
        require_once("{$config['downloady']['rootfolder']}downloady-install.php"); 
        header("Refresh:8");;
    }
    else { $input_errors[] = sprintf(gettext("Installation file %s not found, installation aborted!"), "{$config['downloady']['rootfolder']}downloady-install.php"); }
}
bindtextdomain("nas4free", "/usr/local/share/locale");                  // to get the right main menu language
include("fbegin.inc");
bindtextdomain("nas4free", "/usr/local/share/locale-dly"); ?>
<!-- The Spinner Elements -->
<?php include("ext/downloady/spinner.inc");?>
<script src="ext/downloady/spin.min.js"></script>
<!-- use: onsubmit="spinner()" within the form tag -->

<form action="dly-update_extension.php" method="post" name="iform" id="iform" onsubmit="spinner()">
<table width="100%" border="0" cellpadding="0" cellspacing="0">
	<tr><td class="tabnavtbl">
		<ul id="tabnav">
        <?php if (isset($config['downloady']['enable'])) { ?>
    			<li class="tabinact"><a href="dly-status.php"><span><?=gettext("Download");?></span></a></li>
    			<li class="tabinact"><a href="dly-files.php"><span><?=gettext("Files");?></span></a></li>
    			<li class="tabinact"><a href="dly-config.php"><span><?=gettext("Configuration");?></span></a></li>
    			<li class="tabact"><a href="dly-update_extension.php"><span><?=gettext("Maintenance");?></span></a></li>
        <?php } else { ?>
    			<li class="tabinact"><a href="dly-config.php"><span><?=gettext("Configuration");?></span></a></li>
    			<li class="tabact"><a href="dly-update_extension.php"><span><?=gettext("Maintenance");?></span></a></li>
        <?php } ?>
		</ul>
	</td></tr>
	<tr><td class="tabcont">
        <?php if (!empty($input_errors)) print_input_errors($input_errors);?>
        <?php if (!empty($savemsg)) print_info_box($savemsg);?>
        <table width="100%" border="0" cellpadding="6" cellspacing="0">
            <?php html_titleline(gettext("Extension Update"));?>
			<?php html_text("ext_version_current", gettext("Installed version"), $config['downloady']['version']);?>
			<?php html_text("ext_version_server", gettext("Latest version"), $server_version);?>
			<?php html_separator();?>
        </table>
        <div id="update_remarks">
            <?php html_remark("note_remove", gettext("Note"), sprintf(gettext("Removing %s integration from NAS4Free will leave the installation folder untouched - remove the files using Windows Explorer, FTP or some other tool of your choice. <br /><b>Please note: this page will no longer be available.</b> You'll have to re-run %s extension installation to get it back on your NAS4Free."), gettext("Downloady"), gettext("Downloady")));?>
            <br />
            <input id="ext_update" name="ext_update" type="submit" class="formbtn" value="<?=gettext("Update Extension");?>" onclick="return confirm('<?=gettext("The selected operation will be completed. Please do not click any other buttons!");?>')" />
            <input id="ext_remove" name="ext_remove" type="submit" class="formbtn" value="<?=gettext("Remove Extension");?>" onclick="return confirm('<?=gettext("Do you really want to remove the extension from the system?");?>')" />
        </div>
        <table width="100%" border="0" cellpadding="6" cellspacing="0">
			<?php html_separator();?>
			<?php html_separator();?>
			<?php html_titleline(gettext("Extension")." ".gettext("Release Notes"));?>
			<tr>
                <td class="listt">
                    <div>
                        <textarea style="width: 98%;" id="content" name="content" class="listcontent" cols="1" rows="25" readonly="readonly"><?php unset($lines); exec("/bin/cat {$config['downloady']['rootfolder']}release_notes.txt", $lines); foreach ($lines as $line) { echo $line."\n"; }?></textarea>
                    </div>
                </td>
			</tr>
        </table>
        <?php include("formend.inc");?>
    </td></tr>
</table>
</form>
<?php include("fend.inc");?>
