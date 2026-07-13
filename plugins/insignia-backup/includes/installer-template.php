<?php
/**
 * Insignia Backup — Standalone Installer
 * -------------------------------------------------------------------------
 * This file deploys a backup archive onto a NEW (empty) server.
 * It runs WITHOUT WordPress loaded. Place it in an empty directory
 * alongside the backup .zip, then open it in your browser.
 *
 * Flow:  Step 1 (Validate) → Step 2 (DB credentials) → Step 3 (Deploy)
 *
 * @package InsigniaBackup
 */

error_reporting( E_ERROR | E_PARSE );
@set_time_limit( 0 );
@ini_set( 'memory_limit', '512M' );
session_start();

define( 'IBP_INSTALLER_VERSION', '1.0.0' );
define( 'IBP_ROOT', __DIR__ );
define( 'IBP_LOCK_FILE', IBP_ROOT . '/ibp-installed.lock' );

/**
 * Whether this installer has already completed a deployment.
 *
 * Without this check, installer.php stays a live, unauthenticated "deploy"
 * endpoint for as long as it (and database.sql) remain on the server. Anyone
 * who finds the URL — before the admin remembers to delete these files —
 * could re-run Step 2 and overwrite wp-config.php with THEIR OWN database
 * credentials, silently hijacking the site. Locking after the first
 * successful deploy closes that window.
 *
 * @return bool
 */
function ibp_is_locked() {
	return file_exists( IBP_LOCK_FILE );
}

/* -------------------------------------------------------------------------
 *  Locate the archive (first ibp-*.zip in this folder).
 * ---------------------------------------------------------------------- */
function ibp_find_archive() {
	foreach ( glob( IBP_ROOT . '/ibp-*.zip' ) as $file ) {
		return $file;
	}
	// Fallback: any zip.
	foreach ( glob( IBP_ROOT . '/*.zip' ) as $file ) {
		return $file;
	}
	return '';
}

/* -------------------------------------------------------------------------
 *  AJAX-style action router (POST 'ibp_step').
 * ---------------------------------------------------------------------- */
if ( isset( $_POST['ibp_step'] ) ) {
	header( 'Content-Type: application/json' );
	$step = $_POST['ibp_step'];

	if ( 'extract' === $step ) {
		echo json_encode( ibp_do_extract() );
		exit;
	}
	if ( 'deploy' === $step ) {
		echo json_encode( ibp_do_deploy() );
		exit;
	}
	echo json_encode( [ 'ok' => false, 'msg' => 'Unknown step.' ] );
	exit;
}

/* -------------------------------------------------------------------------
 *  Step: Extract archive into current directory.
 * ---------------------------------------------------------------------- */
function ibp_do_extract() {
	if ( ibp_is_locked() ) {
		return [ 'ok' => false, 'msg' => 'This site has already been deployed. Delete installer.php, database.sql, and ibp-manifest.json from the server — they are no longer needed and leaving them here is a security risk.' ];
	}
	$archive = ibp_find_archive();
	if ( ! $archive ) {
		return [ 'ok' => false, 'msg' => 'No backup archive (.zip) found in this folder.' ];
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return [ 'ok' => false, 'msg' => 'ZipArchive PHP extension is required on this server.' ];
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive ) ) {
		return [ 'ok' => false, 'msg' => 'Could not open the archive.' ];
	}
	$zip->extractTo( IBP_ROOT );
	$count = $zip->numFiles;
	$zip->close();

	if ( ! file_exists( IBP_ROOT . '/database.sql' ) ) {
		return [ 'ok' => false, 'msg' => 'database.sql missing from archive — cannot continue.' ];
	}
	return [ 'ok' => true, 'msg' => 'Extracted ' . $count . ' files.', 'files' => $count ];
}

/* -------------------------------------------------------------------------
 *  Step: Deploy — connect DB, import SQL, search-replace, write wp-config.
 * ---------------------------------------------------------------------- */
function ibp_do_deploy() {
	if ( ibp_is_locked() ) {
		return [ 'ok' => false, 'msg' => 'This site has already been deployed. Delete installer.php, database.sql, and ibp-manifest.json from the server — they are no longer needed and leaving them here is a security risk.' ];
	}

	$host    = trim( $_POST['db_host'] ?? 'localhost' );
	$name    = trim( $_POST['db_name'] ?? '' );
	$user    = trim( $_POST['db_user'] ?? '' );
	$pass    = $_POST['db_pass'] ?? '';
	$prefix  = trim( $_POST['db_prefix'] ?? 'wp_' );
	$new_url = rtrim( trim( $_POST['new_url'] ?? '' ), '/' );

	if ( '' === $name || '' === $user ) {
		return [ 'ok' => false, 'msg' => 'Database name and user are required.' ];
	}

	// 1. Connect.
	$mysqli = @new mysqli( $host, $user, $pass, $name );
	if ( $mysqli->connect_errno ) {
		return [ 'ok' => false, 'msg' => 'DB connection failed: ' . $mysqli->connect_error ];
	}
	$mysqli->set_charset( 'utf8mb4' );

	// 2. Read manifest for old URL.
	$old_url = '';
	if ( file_exists( IBP_ROOT . '/ibp-manifest.json' ) ) {
		$manifest = json_decode( file_get_contents( IBP_ROOT . '/ibp-manifest.json' ), true );
		$old_url  = rtrim( $manifest['site']['home_url'] ?? '', '/' );
	}

	// 3. Import SQL.
	$sql = file_get_contents( IBP_ROOT . '/database.sql' );
	if ( false === $sql ) {
		return [ 'ok' => false, 'msg' => 'Could not read database.sql.' ];
	}
	if ( ! $mysqli->multi_query( $sql ) ) {
		return [ 'ok' => false, 'msg' => 'SQL import failed: ' . $mysqli->error ];
	}
	// Drain all result sets.
	do {
		if ( $res = $mysqli->store_result() ) { $res->free(); }
	} while ( $mysqli->more_results() && $mysqli->next_result() );

	// 4. Search-replace old URL → new URL (handles serialized data).
	$replaced = 0;
	if ( $old_url && $new_url && $old_url !== $new_url ) {
		$replaced = ibp_search_replace( $mysqli, $prefix, $old_url, $new_url );
	}

	$mysqli->close();

	// 5. Write a fresh wp-config.php.
	$cfg_ok = ibp_write_wp_config( $host, $name, $user, $pass, $prefix );

	// Lock the installer so it can't be pointed at a different database later.
	@file_put_contents( IBP_LOCK_FILE, 'Deployed: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );

	return [
		'ok'       => true,
		'msg'      => 'Deployment complete.',
		'replaced' => $replaced,
		'config'   => $cfg_ok,
		'old_url'  => $old_url,
		'new_url'  => $new_url,
	];
}

/* -------------------------------------------------------------------------
 *  Serialized-safe search & replace across all tables.
 * ---------------------------------------------------------------------- */
function ibp_search_replace( $mysqli, $prefix, $old, $new ) {
	$total  = 0;
	$tables = [];
	$rs     = $mysqli->query( 'SHOW TABLES' );
	while ( $row = $rs->fetch_array() ) {
		$tables[] = $row[0];
	}
	$rs->free();

	foreach ( $tables as $table ) {
		// Get primary key + text-ish columns.
		$cols    = [];
		$pk      = '';
		$col_res = $mysqli->query( "SHOW COLUMNS FROM `$table`" );
		while ( $c = $col_res->fetch_assoc() ) {
			$cols[] = $c['Field'];
			if ( 'PRI' === $c['Key'] && '' === $pk ) {
				$pk = $c['Field'];
			}
		}
		$col_res->free();
		if ( ! $pk ) {
			continue;
		}

		$data = $mysqli->query( "SELECT * FROM `$table`" );
		while ( $r = $data->fetch_assoc() ) {
			$changed = false;
			$updates = [];
			foreach ( $r as $field => $value ) {
				if ( $field === $pk || null === $value ) {
					continue;
				}
				if ( false !== strpos( $value, $old ) ) {
					$newval = ibp_recursive_unserialize_replace( $value, $old, $new );
					if ( $newval !== $value ) {
						$updates[ $field ] = $newval;
						$changed           = true;
					}
				}
			}
			if ( $changed ) {
				$sets = [];
				foreach ( $updates as $f => $v ) {
					$sets[] = "`$f` = '" . $mysqli->real_escape_string( $v ) . "'";
				}
				$pk_val = $mysqli->real_escape_string( $r[ $pk ] );
				$mysqli->query( "UPDATE `$table` SET " . implode( ', ', $sets ) . " WHERE `$pk` = '$pk_val'" );
				$total++;
			}
		}
		$data->free();
	}
	return $total;
}

/**
 * Replace within (possibly serialized) data while keeping length prefixes valid.
 */
function ibp_recursive_unserialize_replace( $data, $old, $new ) {
	$unserialized = @unserialize( $data );
	if ( false !== $unserialized || 'b:0;' === $data ) {
		$replaced = ibp_replace_in_structure( $unserialized, $old, $new );
		return serialize( $replaced );
	}
	if ( is_string( $data ) ) {
		return str_replace( $old, $new, $data );
	}
	return $data;
}

function ibp_replace_in_structure( $value, $old, $new ) {
	if ( is_array( $value ) ) {
		$out = [];
		foreach ( $value as $k => $v ) {
			$out[ $k ] = ibp_replace_in_structure( $v, $old, $new );
		}
		return $out;
	}
	if ( is_object( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value->$k = ibp_replace_in_structure( $v, $old, $new );
		}
		return $value;
	}
	if ( is_string( $value ) ) {
		return str_replace( $old, $new, $value );
	}
	return $value;
}

/* -------------------------------------------------------------------------
 *  Patch / write wp-config.php with new DB credentials.
 * ---------------------------------------------------------------------- */

/**
 * Replace a regex match with a literal string, safely.
 *
 * preg_replace() treats $ and \ in its *replacement* argument as backreference
 * syntax (e.g. "$1", "\1"), so a database password containing a literal "$"
 * — very common in host-generated passwords — would come out corrupted or
 * truncated in the rewritten wp-config.php. preg_replace_callback() inserts
 * its return value verbatim, with no such interpretation, so it's used here
 * instead of preg_replace() for every value that isn't a fixed constant.
 *
 * @param string $cfg     File contents.
 * @param string $pattern Regex pattern.
 * @param string $literal Exact replacement text.
 * @return string
 */
function ibp_cfg_replace( $cfg, $pattern, $literal ) {
	return preg_replace_callback(
		$pattern,
		static function () use ( $literal ) {
			return $literal;
		},
		$cfg
	);
}

function ibp_write_wp_config( $host, $name, $user, $pass, $prefix ) {
	$path = IBP_ROOT . '/wp-config.php';
	if ( ! file_exists( $path ) ) {
		return false;
	}
	$cfg = file_get_contents( $path );

	$cfg = ibp_cfg_replace( $cfg, "/define\(\s*'DB_NAME'.*?\);/s",     "define( 'DB_NAME', '" . addslashes( $name ) . "' );" );
	$cfg = ibp_cfg_replace( $cfg, "/define\(\s*'DB_USER'.*?\);/s",     "define( 'DB_USER', '" . addslashes( $user ) . "' );" );
	$cfg = ibp_cfg_replace( $cfg, "/define\(\s*'DB_PASSWORD'.*?\);/s", "define( 'DB_PASSWORD', '" . addslashes( $pass ) . "' );" );
	$cfg = ibp_cfg_replace( $cfg, "/define\(\s*'DB_HOST'.*?\);/s",     "define( 'DB_HOST', '" . addslashes( $host ) . "' );" );
	$cfg = ibp_cfg_replace( $cfg, "/\\\$table_prefix\s*=\s*['\"].*?['\"];/", "\$table_prefix = '" . addslashes( $prefix ) . "';" );

	return false !== @file_put_contents( $path, $cfg );
}

$archive_found = ibp_find_archive();
$zip_ok        = class_exists( 'ZipArchive' );
$locked        = ibp_is_locked();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Insignia Backup — Installer</title>
<style>
	:root{
		--ink:#16231e;--soft:#5a6b64;--line:#e3e8e6;--em:#1a7f5a;--emd:#0f5e41;
		--eml:#e7f4ee;--gold:#c79a3a;--danger:#c2452f;--bg:#f4f6f5;
	}
	*{box-sizing:border-box}
	body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
		background:var(--bg);color:var(--ink);line-height:1.55;min-height:100vh;
		display:flex;align-items:flex-start;justify-content:center;padding:40px 20px}
	.card{background:#fff;width:100%;max-width:640px;border:1px solid var(--line);
		border-radius:18px;box-shadow:0 30px 70px -30px rgba(16,40,32,.4);overflow:hidden}
	.head{padding:28px 32px;background:linear-gradient(145deg,var(--em),var(--emd));color:#fff}
	.head h1{margin:0;font-size:22px;letter-spacing:-.02em;display:flex;align-items:center;gap:12px}
	.head p{margin:6px 0 0;opacity:.85;font-size:13.5px}
	.body{padding:30px 32px}
	.steps{display:flex;gap:8px;margin-bottom:26px}
	.dot{flex:1;height:6px;border-radius:99px;background:var(--line)}
	.dot.on{background:var(--em)}
	.row{margin-bottom:16px}
	label{display:block;font-weight:600;font-size:13px;margin-bottom:6px}
	input{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 13px;
		font-size:14px;transition:border-color .15s,box-shadow .15s}
	input:focus{outline:0;border-color:var(--em);box-shadow:0 0 0 3px rgba(26,127,90,.14)}
	.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
	.btn{border:0;cursor:pointer;font-weight:600;font-size:14.5px;padding:13px 26px;
		border-radius:11px;color:#fff;background:linear-gradient(145deg,var(--em),var(--emd));
		box-shadow:0 12px 24px -10px rgba(15,94,65,.7);transition:transform .12s}
	.btn:active{transform:translateY(1px)}.btn:disabled{opacity:.5;cursor:not-allowed}
	.note{font-size:12.5px;color:var(--soft);margin-top:4px}
	.alert{padding:13px 16px;border-radius:10px;font-size:13.5px;margin-bottom:18px}
	.alert.warn{background:#fbf6e8;border:1px solid #f0e2bf;color:#8a6516}
	.alert.err{background:#fbe6e1;border:1px solid #f1c4ba;color:var(--danger)}
	.alert.ok{background:var(--eml);border:1px solid #bfe3d2;color:var(--emd)}
	.hide{display:none}
	.log{font-family:ui-monospace,Menlo,monospace;font-size:12.5px;background:#0f1f1a;color:#9fe6c4;
		padding:14px 16px;border-radius:10px;margin-top:16px;max-height:180px;overflow:auto;white-space:pre-wrap}
	.spinner{display:inline-block;width:15px;height:15px;border:2px solid rgba(255,255,255,.4);
		border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:-2px;margin-right:6px}
	@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="card">
	<div class="head">
		<h1>&#128737; Insignia Backup Installer</h1>
		<p><?php echo $locked ? 'This site has already been deployed.' : 'Deploy your site to this server in three steps.'; ?></p>
	</div>
	<div class="body">
	<?php if ( $locked ) : ?>
		<div class="alert ok"><strong>&#10003; Already deployed.</strong> This installer already completed a deployment on this server and has locked itself to prevent it from being run again with different database credentials.</div>
		<p class="note"><strong>For security, delete these files now:</strong> <code>installer.php</code>, <code>database.sql</code>, <code>ibp-manifest.json</code>, and <code>ibp-installed.lock</code>.</p>
		<p class="note">If you genuinely need to redeploy (e.g. this was a mistake), delete <code>ibp-installed.lock</code> from the server first.</p>
	<?php else : ?>
		<div class="steps"><span class="dot on" id="d1"></span><span class="dot" id="d2"></span><span class="dot" id="d3"></span></div>

		<?php if ( ! $zip_ok ) : ?>
			<div class="alert err">ZipArchive PHP extension is not available. The installer cannot extract the archive on this server.</div>
		<?php elseif ( ! $archive_found ) : ?>
			<div class="alert warn">No backup archive (<code>ibp-*.zip</code>) found in this folder. Upload it next to this installer.</div>
		<?php endif; ?>

		<!-- STEP 1 -->
		<section id="step1">
			<div class="alert ok">Archive detected: <strong><?php echo htmlspecialchars( basename( $archive_found ) ); ?></strong></div>
			<p class="note">Step 1 extracts your site files and database dump into this directory.</p>
			<button class="btn" id="btn-extract" <?php echo ( $zip_ok && $archive_found ) ? '' : 'disabled'; ?>>Extract &amp; Validate</button>
			<div class="log hide" id="log1"></div>
		</section>

		<!-- STEP 2 -->
		<section id="step2" class="hide">
			<div class="alert ok">Files extracted. Now enter the database for this server.</div>
			<div class="grid">
				<div class="row"><label>Database Host</label><input id="db_host" value="localhost"></div>
				<div class="row"><label>Database Name</label><input id="db_name" placeholder="my_database"></div>
				<div class="row"><label>Database User</label><input id="db_user" placeholder="db_user"></div>
				<div class="row"><label>Database Password</label><input id="db_pass" type="password"></div>
				<div class="row"><label>Table Prefix</label><input id="db_prefix" value="wp_"></div>
				<div class="row"><label>New Site URL</label><input id="new_url" placeholder="https://newsite.com"></div>
			</div>
			<p class="note">URLs in the database are auto-rewritten from the old domain to the new one (serialized data safe).</p>
			<button class="btn" id="btn-deploy">Deploy Site</button>
			<div class="log hide" id="log2"></div>
		</section>

		<!-- STEP 3 -->
		<section id="step3" class="hide">
			<div class="alert ok"><strong>&#10003; All done!</strong> Your site has been deployed.</div>
			<div class="log" id="log3"></div>
			<p class="note" style="margin-top:18px"><strong>Important:</strong> for security, delete <code>installer.php</code>, <code>database.sql</code> and <code>ibp-manifest.json</code> from this server now.</p>
			<a class="btn" id="btn-visit" href="#" style="display:inline-block;text-decoration:none;margin-top:6px">Visit Your Site &rarr;</a>
		</section>
	<?php endif; ?>
	</div>
</div>

<?php if ( ! $locked ) : ?>
<script>
( function () {
	function post( data, cb ) {
		var body = Object.keys( data ).map( function ( k ) {
			return encodeURIComponent( k ) + '=' + encodeURIComponent( data[ k ] );
		} ).join( '&' );
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', window.location.href, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onreadystatechange = function () {
			if ( 4 === xhr.readyState ) {
				try { cb( JSON.parse( xhr.responseText ) ); }
				catch ( e ) { cb( { ok: false, msg: 'Server error: ' + xhr.responseText.slice( 0, 200 ) } ); }
			}
		};
		xhr.send( body );
	}
	function show( id ) { document.getElementById( id ).classList.remove( 'hide' ); }
	function log( id, text ) { var el = document.getElementById( id ); el.classList.remove( 'hide' ); el.textContent += text + '\n'; }

	// Step 1.
	document.getElementById( 'btn-extract' ).onclick = function () {
		var b = this; b.disabled = true; b.innerHTML = '<span class="spinner"></span>Extracting…';
		log( 'log1', 'Opening archive…' );
		post( { ibp_step: 'extract' }, function ( r ) {
			log( 'log1', r.msg );
			if ( r.ok ) {
				document.getElementById( 'd2' ).classList.add( 'on' );
				document.getElementById( 'step1' ).classList.add( 'hide' );
				show( 'step2' );
			} else {
				b.disabled = false; b.textContent = 'Retry Extract';
			}
		} );
	};

	// Step 2.
	document.getElementById( 'btn-deploy' ).onclick = function () {
		var b = this; b.disabled = true; b.innerHTML = '<span class="spinner"></span>Deploying…';
		var data = {
			ibp_step: 'deploy',
			db_host: val( 'db_host' ), db_name: val( 'db_name' ),
			db_user: val( 'db_user' ), db_pass: val( 'db_pass' ),
			db_prefix: val( 'db_prefix' ), new_url: val( 'new_url' )
		};
		log( 'log2', 'Connecting to database…' );
		post( data, function ( r ) {
			log( 'log2', r.msg );
			if ( r.ok ) {
				document.getElementById( 'd3' ).classList.add( 'on' );
				document.getElementById( 'step2' ).classList.add( 'hide' );
				show( 'step3' );
				log( 'log3', 'Database imported.' );
				if ( r.replaced ) { log( 'log3', 'URLs rewritten in ' + r.replaced + ' rows (' + r.old_url + ' → ' + r.new_url + ').' ); }
				log( 'log3', 'wp-config.php updated: ' + ( r.config ? 'yes' : 'no' ) );
				var url = val( 'new_url' ) || '/';
				document.getElementById( 'btn-visit' ).href = url;
			} else {
				b.disabled = false; b.textContent = 'Retry Deploy';
			}
		} );
	};

	function val( id ) { return document.getElementById( id ).value; }
} )();
</script>
<?php endif; ?>
</body>
</html>
