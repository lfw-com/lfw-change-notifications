#!/usr/bin/env bash
# End-to-end proof for LFW Change Notifications: a throwaway WordPress in Docker with WP-CLI.
# Mail is captured by tests/mu-test-harness.php (a pre_wp_mail filter), so nothing is sent.
# The same mu-plugin moves the plugin's clock forward so the ten-minute window can pass.
#   ./test.sh          run the checks
#   KEEP=1 ./test.sh   leave WordPress running on :8097 (admin / admin)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; PORT="${PORT:-8097}"
TMP="$(mktemp -d)"; FAILED=0; NET=lfwcn; DBC=lfwcn-db; WPC=lfwcn-wp
cleanup(){ [ -n "${KEEP:-}" ] || { docker rm -f "$DBC" "$WPC" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }; rm -rf "$TMP"; }
docker rm -f "$DBC" "$WPC" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; trap cleanup EXIT
check(){ if [ "$2" = "$3" ]; then echo "  ok    $1"; else echo "  FAIL  $1 (got '$2', want '$3')"; FAILED=1; fi; }
has(){ if grep -qF -- "$3" <<<"$2"; then echo "  ok    $1"; else echo "  FAIL  $1 (missing: $3)"; FAILED=1; fi; }
hasnt(){ if grep -qF -- "$3" <<<"$2"; then echo "  FAIL  $1 (found: $3)"; FAILED=1; else echo "  ok    $1"; fi; }

echo "==> WordPress: start and install"
BASE="http://127.0.0.1:$PORT"
EXTRA="define('WP_HOME','$BASE'); define('WP_SITEURL','$BASE'); define('DISABLE_WP_CRON', true); define('WP_ENVIRONMENT_TYPE','local');"
ENVF="$TMP/env"; printf 'WORDPRESS_CONFIG_EXTRA=%s\n' "$EXTRA" > "$ENVF"
PLUG=/var/www/html/wp-content/plugins/lfw-change-notifications
docker network create "$NET" >/dev/null
docker run -d --name "$DBC" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name "$WPC" --network "$NET" -p "127.0.0.1:$PORT:80" -e WORDPRESS_DB_HOST="$DBC" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp -e WORDPRESS_CONFIG_EXTRA="$EXTRA" \
  -v "$HERE/lfw-change-notifications.php:$PLUG/lfw-change-notifications.php:ro" \
  -v "$HERE/uninstall.php:$PLUG/uninstall.php:ro" \
  -v "$HERE/includes:$PLUG/includes:ro" \
  -v "$HERE/tests/mu-test-harness.php:/var/www/html/wp-content/mu-plugins/mu-test-harness.php:ro" wordpress:6.8-php8.2-apache >/dev/null
for i in $(seq 1 60); do docker exec "$DBC" mariadb -uwp -pwp -e 'select 1' wp >/dev/null 2>&1 && curl -s -o /dev/null "$BASE/" && break; sleep 2; done; sleep 3
WP="docker run --rm --network $NET --volumes-from $WPC --user 33:33 --env-file $ENVF -e WORDPRESS_DB_HOST=$DBC -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp wordpress:cli-php8.2 wp"
docker exec "$WPC" chown www-data:www-data /var/www/html/wp-content
$WP core install --url="$BASE" --title="Park QA" --admin_user=admin --admin_password=admin --admin_email=webmaster@example.test --skip-email >/dev/null
$WP option update timezone_string America/New_York >/dev/null
$WP plugin activate lfw-change-notifications >/dev/null

# Helpers: count captured mail, read the last one, let time pass, reset between scenarios.
mails(){ $WP eval 'echo count( get_option( "lfwcn_test_mail", array() ) );'; }
subject(){ $WP eval '$m = get_option( "lfwcn_test_mail", array() ); echo $m ? end( $m )["subject"] : "";'; }
body(){ $WP eval '$m = get_option( "lfwcn_test_mail", array() ); echo $m ? end( $m )["message"] : "";'; }
to(){ $WP eval '$m = get_option( "lfwcn_test_mail", array() ); echo $m ? implode( ",", (array) end( $m )["to"] ) : "";'; }
waiting(){ $WP eval 'echo LFWCN_Queue::count();'; }
advance(){ local off; off=$($WP option get lfwcn_test_offset 2>/dev/null || echo 0); $WP option update lfwcn_test_offset $((off + $1)) >/dev/null; }
fresh(){ advance 700; $WP option delete lfwcn_test_mail >/dev/null 2>&1 || true; }
flush(){ $WP cron event run lfwcn_flush >/dev/null 2>&1 || true; }
settings(){ $WP eval "update_option( 'lfwcn_settings', LFWCN_Settings::sanitize( $1 ) );"; }

echo "==> Lint"
for f in lfw-change-notifications.php uninstall.php includes/class-lfwcn-settings.php includes/class-lfwcn-queue.php includes/class-lfwcn-watcher.php includes/class-lfwcn-mailer.php includes/class-lfwcn-delivery.php; do
  check "$f lints on PHP 8.2" "$(docker exec "$WPC" php -l "$PLUG/$f" 2>&1 | head -1)" "No syntax errors detected in $PLUG/$f"
done

echo "==> Defaults"
check "recipients default to the site admin email" "$($WP eval 'echo implode( ",", LFWCN_Settings::recipients() );')" "webmaster@example.test"
check "posts and pages are watched by default" "$($WP eval '$t = LFWCN_Settings::get()["post_types"]; echo in_array( "post", $t, true ) && in_array( "page", $t, true ) && ! in_array( "attachment", $t, true ) ? "yes" : "no";')" "yes"
check "delivery defaults to right away" "$($WP eval 'echo LFWCN_Settings::get()["delivery"];')" "immediate"

echo "==> Publish"
P1=$($WP post create --post_type=page --post_status=publish --post_title="Spring program guide" --post_content="First text." --user=admin --porcelain)
check "publishing sends one email" "$(mails)" "1"
check "the subject names the event and the title" "$(subject)" "[Park QA] Published: Spring program guide"
check "it goes to the site admin email" "$(to)" "webmaster@example.test"
B=$(body)
has "the body says who" "$B" "By: admin (admin)"
has "the body says what type" "$B" "Type: Page"
has "the body says when, in the site time zone" "$B" "(America/New_York)"
has "the body links to the page" "$B" "View: $BASE/?page_id=$P1"
has "the body links to the editor" "$B" "Edit: $BASE/wp-admin/post.php?post=$P1&action=edit"
has "the body says why the reader gets it" "$B" "listed in Settings > Change Notifications"
check "the queue is empty after sending" "$(waiting)" "0"

echo "==> Collapse: repeated saves of one item"
$WP post update "$P1" --post_content="Second text." --user=admin >/dev/null
$WP post update "$P1" --post_content="Third text." --user=admin >/dev/null
check "saves within ten minutes of the last email send nothing yet" "$(mails)" "1"
check "they wait as one entry" "$(waiting)" "1"
check "a follow-up send is scheduled" "$($WP eval 'echo wp_next_scheduled( LFWCN_Delivery::FLUSH_HOOK ) ? "yes" : "no";')" "yes"
flush
check "before the window ends, the scheduled send holds them" "$(mails)" "1"
advance 601; flush
check "after the window, one email covers both saves" "$(mails)" "2"
check "its subject says updated" "$(subject)" "[Park QA] Updated: Spring program guide"
B=$(body)
has "it counts the saves" "$B" "Saves: 2, the first at"
has "it links to the revision comparison" "$B" "Compare revisions: $BASE/wp-admin/revision.php?revision="
check "the queue is empty" "$(waiting)" "0"

echo "==> Update after the window: sent right away"
fresh
$WP post update "$P1" --post_title="Spring program guide (revised)" --user=admin >/dev/null
check "an update with no recent email is sent at once" "$(mails)" "1"
check "subject" "$(subject)" "[Park QA] Updated: Spring program guide (revised)"
has "it links to the revision comparison" "$(body)" "Compare revisions:"

echo "==> The block editor's save path (REST API)"
fresh
APP=$($WP user application-password create admin test --porcelain)
# The block editor first saves a draft, then publishes it with an update.
RID=$(curl -s -u "admin:$APP" -H 'Content-Type: application/json' -d '{"title":"Trail map","status":"draft","content":"Map."}' "$BASE/?rest_route=/wp/v2/posts" | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
check "a REST draft sends nothing" "$(mails)" "0"
curl -s -o /dev/null -u "admin:$APP" -H 'Content-Type: application/json' -d '{"status":"publish"}' "$BASE/?rest_route=/wp/v2/posts/$RID"
check "publishing through the REST API sends one email" "$(mails)" "1"
check "subject" "$(subject)" "[Park QA] Published: Trail map"
advance 700
curl -s -o /dev/null -u "admin:$APP" -H 'Content-Type: application/json' -d '{"content":"Map, with the new loop."}' "$BASE/?rest_route=/wp/v2/posts/$RID"
check "an update through the REST API sends one email" "$(mails)" "2"
has "it links to the revision comparison" "$(body)" "Compare revisions:"

echo "==> Not reported: drafts, pending, autosaves"
fresh
D=$($WP post create --post_status=draft --post_title="Draft notice" --user=admin --porcelain)
$WP post update "$D" --post_content="More draft." --user=admin >/dev/null
$WP post update "$D" --post_status=pending --user=admin >/dev/null
check "draft and pending saves send nothing" "$(mails)" "0"
$WP eval "wp_create_post_autosave( array( 'post_ID' => $P1, 'post_type' => 'page', 'post_title' => 'Autosaved title', 'post_content' => 'Autosaved text.', 'post_excerpt' => '' ) );" --user=admin >/dev/null
check "an autosave sends nothing" "$(mails)" "0"
$WP eval "define( 'DOING_AUTOSAVE', true ); wp_update_post( array( 'ID' => $P1, 'post_content' => 'Autosave request.' ) );" --user=admin >/dev/null
check "a save during an autosave request sends nothing" "$(mails)" "0"
check "nothing is queued" "$(waiting)" "0"

echo "==> Scheduled, unpublished, trashed, deleted"
fresh
F=$($WP post create --post_status=future --post_date="2030-01-01 09:00:00" --post_title="Summer camp signups" --user=admin --porcelain)
check "scheduling sends an email" "$(subject)" "[Park QA] Scheduled: Summer camp signups"
fresh
U=$($WP post create --post_status=publish --post_title="Pool hours" --user=admin --porcelain)
advance 700
$WP post update "$U" --post_status=draft --user=admin >/dev/null
check "unpublishing sends an email" "$(subject)" "[Park QA] Unpublished: Pool hours"
fresh
T=$($WP post create --post_status=publish --post_title="Old closure notice" --user=admin --porcelain)
advance 700
$WP post delete "$T" --user=admin >/dev/null
check "trashing sends an email" "$(subject)" "[Park QA] Moved to trash: Old closure notice"
has "it links to the trash" "$(body)" "Restore from the trash: $BASE/wp-admin/edit.php?post_status=trash&post_type=post"
fresh
X=$($WP post create --post_status=publish --post_title="Wrong flyer" --user=admin --porcelain)
advance 700
$WP post delete "$X" --force --user=admin >/dev/null
check "permanent deletion sends an email" "$(subject)" "[Park QA] Deleted: Wrong flyer"
has "it says the item is gone" "$(body)" "This item has been deleted permanently."

echo "==> A title cannot add headers or markup"
fresh
$WP post create --post_status=publish --post_title=$'Fish &amp; chips <b>night</b>\r\nBcc: someone@evil.test' --user=admin --porcelain >/dev/null
S=$(subject)
check "the subject is one plain line" "$S" "[Park QA] Published: Fish & chips night Bcc: someone@evil.test"
check "the recipients are unchanged" "$(to)" "webmaster@example.test"

echo "==> Import: 200 posts in one run is one email"
fresh
$WP post generate --count=200 --post_status=publish --post_title="Imported item" >/dev/null
check "200 published posts send one email" "$(mails)" "1"
check "subject counts them" "$(subject)" "[Park QA] 200 content changes"
B=$(body)
has "the body lists the first 50 and counts the rest" "$B" "And 150 more changes, not listed here."
check "it lists exactly 50 items" "$(grep -c '^Published: ' <<<"$B")" "50"

echo "==> Bulk edit in the dashboard: 200 posts is one email"
fresh
JAR="$TMP/jar"; curl -s -c "$JAR" -o /dev/null "$BASE/wp-login.php"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -d "log=admin&pwd=admin&testcookie=1&wp-submit=Log+In&redirect_to=%2Fwp-admin%2F" "$BASE/wp-login.php"
LIST=$(curl -s -b "$JAR" "$BASE/wp-admin/edit.php?post_type=post")
BN=$(grep -oE 'name="_wpnonce" value="[a-f0-9]+"' <<<"$LIST" | head -1 | grep -oE '[a-f0-9]{10}')
IDS=$($WP post list --post_type=post --post_status=publish --orderby=ID --order=DESC --field=ID --posts_per_page=200 | tr -d '\r')
ARGS=(-d "_wpnonce=$BN" -d "action=edit" -d "bulk_edit=Update" -d "post_type=post" -d "_status=-1" -d "post_author=-1" -d "comment_status=" -d "ping_status=" -d "sticky=-1" -d "post_format=-1")
for id in $IDS; do ARGS+=(-d "post[]=$id"); done
curl -s -b "$JAR" -o /dev/null "${ARGS[@]}" "$BASE/wp-admin/edit.php"
check "the bulk edit touched 200 posts" "$(wc -w <<<"$IDS" | tr -d ' ')" "200"
check "a bulk edit of 200 posts sends one email" "$(mails)" "1"
check "subject counts them" "$(subject)" "[Park QA] 200 content changes"
has "the body says updated" "$(body)" "Updated: Imported item"

echo "==> Burst limit: many separate saves in a few minutes"
fresh
for id in $(head -7 <<<"$IDS"); do $WP post update "$id" --post_content="Edited one by one." --user=admin >/dev/null; done
check "seven separate saves send at most five emails" "$(mails)" "5"
check "the other two wait" "$(waiting)" "2"
advance 601; flush
check "after the window they go out in one email" "$(mails)" "6"
check "subject counts them" "$(subject)" "[Park QA] 2 content changes"

echo "==> Visitors cannot trigger mail"
fresh
# Core's own comment emails are turned off so any mail here would be this plugin's.
$WP option update moderation_notify 0 >/dev/null; $WP option update comments_notify 0 >/dev/null
check "a post created with no signed-in user" "$(curl -s "$BASE/?lfwcn_test_visitor_post=1")" "created"
CID=$($WP post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID | tr -d '\r')
curl -s -o /dev/null -d "comment=Nice+post&author=Stranger&email=stranger%40example.test&comment_post_ID=$CID" "$BASE/wp-comments-post.php"
check "neither sends anything" "$(mails)" "0"
check "nor queues anything" "$(waiting)" "0"

echo "==> Event and content type choices"
fresh
settings "array( 'recipients' => 'ops@example.test', 'post_types' => array( 'post' ), 'events' => array( 'published' ), 'delivery' => 'immediate' )"
$WP post update "$U" --post_status=publish --user=admin >/dev/null
check "a turned-on event is sent to the new recipient" "$(to)" "ops@example.test"
advance 700
$WP post update "$U" --post_content="Updated pool hours." --user=admin >/dev/null
$WP post create --post_type=page --post_status=publish --post_title="A new page" --user=admin >/dev/null
check "a turned-off event and an unwatched type send nothing" "$(mails)" "1"

echo "==> Digest"
fresh
settings "array( 'recipients' => 'ops@example.test', 'post_types' => array( 'post', 'page' ), 'events' => array( 'published', 'updated' ), 'delivery' => 'hourly' )"
check "an hourly digest is scheduled" "$($WP eval 'echo wp_get_schedule( LFWCN_Delivery::DIGEST_HOOK );')" "hourly"
check "no right-away send is scheduled" "$($WP eval 'echo wp_next_scheduled( LFWCN_Delivery::FLUSH_HOOK ) ? "yes" : "no";')" "no"
G1=$($WP post create --post_status=publish --post_title="Fall league" --user=admin --porcelain)
$WP post create --post_type=page --post_status=publish --post_title="Board meeting" --user=admin >/dev/null
$WP post update "$G1" --post_content="Fall league details." --user=admin >/dev/null
$WP post update "$G1" --post_content="Fall league details, again." --user=admin >/dev/null
check "changes wait for the digest" "$(mails)" "0"
check "four saves of two items wait as two entries" "$(waiting)" "2"
$WP cron event run lfwcn_digest >/dev/null
check "the digest is one email" "$(mails)" "1"
check "subject" "$(subject)" "[Park QA] Hourly digest: 2 content changes"
B=$(body)
has "the item saved three times is described once" "$B" "Published, then updated: Fall league"
has "with its save count" "$B" "Saves: 3"
check "the queue is empty after the digest" "$(waiting)" "0"
settings "array( 'recipients' => 'ops@example.test', 'delivery' => 'daily', 'events' => array( 'published', 'updated' ), 'post_types' => array( 'post', 'page' ) )"
check "switching to daily reschedules the digest" "$($WP eval 'echo wp_get_schedule( LFWCN_Delivery::DIGEST_HOOK );')" "daily"

echo "==> Imports can be skipped"
fresh
settings "array( 'recipients' => 'ops@example.test', 'delivery' => 'immediate', 'events' => array( 'published', 'updated' ), 'post_types' => array( 'post', 'page' ), 'ignore_imports' => '1' )"
$WP post create --post_status=publish --post_title="Bulk import row" --user=admin >/dev/null
check "with the setting on, WP-CLI changes send nothing" "$(mails)" "0"
check "and queue nothing" "$(waiting)" "0"
settings "array( 'delivery' => 'immediate' )"

echo "==> Settings sanitization"
SAN=$($WP eval '
$many = array();
for ( $i = 1; $i <= 25; $i++ ) { $many[] = "staff$i@example.test"; }
$s = LFWCN_Settings::sanitize( array(
  "recipients"     => "Bad Address, <script>@x.test; ops@example.test OPS@example.test\n" . implode( "\n", $many ),
  "post_types"     => array( "post", "nav_menu_item", "attachment", "no_such_type" ),
  "events"         => array( "updated", "everything" ),
  "delivery"       => "weekly",
  "ignore_imports" => "",
) );
echo count( $s["recipients"] ), "|", $s["recipients"][0], "|", implode( ",", $s["post_types"] ), "|", implode( ",", $s["events"] ), "|", $s["delivery"], "|", var_export( $s["ignore_imports"], true );
')
check "valid, unique addresses capped at 20; allowlisted types, events and delivery" "$SAN" "20|ops@example.test|post|updated|immediate|false"
check "a non-array submission becomes the defaults" "$($WP eval '$s = LFWCN_Settings::sanitize( "junk" ); echo count( $s["recipients"] ), "|", $s["delivery"];')" "0|immediate"

echo "==> Settings screen"
PAGE=$(curl -s -b "$JAR" "$BASE/wp-admin/options-general.php?page=lfw-change-notifications")
has "the screen renders under Settings" "$PAGE" "<h1>Change Notifications</h1>"
has "choices are grouped in fieldsets with legends" "$PAGE" '<legend class="screen-reader-text">Events</legend>'
ON=$(tr '\n' ' ' <<<"$PAGE" | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
R=$(curl -s -L -b "$JAR" -d "option_page=lfwcn&action=update&_wpnonce=$ON&_wp_http_referer=%2Fwp-admin%2Foptions-general.php%3Fpage%3Dlfw-change-notifications" \
  --data-urlencode $'lfwcn_settings[recipients]=parks@example.test\n<img/src=x/onerror=alert(1)>' \
  -d "lfwcn_settings[post_types][]=post" -d "lfwcn_settings[events][]=published" -d "lfwcn_settings[delivery]=hourly" "$BASE/wp-admin/options.php")
check "the form saves a clean recipient list" "$($WP eval 'echo implode( ",", LFWCN_Settings::get()["recipients"] );')" "parks@example.test"
has "it says an address was removed" "$R" "1 address was not a valid email address and was removed."
hasnt "the rejected text is never echoed" "$R" "onerror=alert(1)"
check "saving through the form schedules the digest" "$($WP eval 'echo wp_get_schedule( LFWCN_Delivery::DIGEST_HOOK );')" "hourly"
NO=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -d "option_page=lfwcn&action=update&lfwcn_settings[recipients]=evil@example.test" "$BASE/wp-admin/options.php")
check "a save without a nonce is refused" "$NO" "403"
check "and changes nothing" "$($WP eval 'echo implode( ",", LFWCN_Settings::get()["recipients"] );')" "parks@example.test"
$WP user create casey casey@example.test --role=editor --user_pass=casey >/dev/null
EJAR="$TMP/ejar"; curl -s -c "$EJAR" -o /dev/null "$BASE/wp-login.php"; curl -s -b "$EJAR" -c "$EJAR" -o /dev/null -d "log=casey&pwd=casey&testcookie=1&wp-submit=Log+In" "$BASE/wp-login.php"
hasnt "an editor cannot open the screen" "$(curl -s -b "$EJAR" "$BASE/wp-admin/options-general.php?page=lfw-change-notifications")" "<h1>Change Notifications</h1>"
check "an editor cannot send a test email" "$(curl -s -o /dev/null -w '%{http_code}' -b "$EJAR" -d action=lfwcn_test "$BASE/wp-admin/admin-post.php")" "403"

echo "==> Test email"
fresh
TN=$(tr '\n' ' ' <<<"$PAGE" | grep -oE 'name="action" value="lfwcn_test">.{0,300}' | grep -oE 'name="_wpnonce" value="[a-f0-9]+"' | head -1 | grep -oE '[a-f0-9]{10}')
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -d "action=lfwcn_test&_wpnonce=$TN" "$BASE/wp-admin/admin-post.php")
has "the test email is sent" "$LOC" "lfwcn-test=sent"
check "it goes to the saved recipients" "$(to)" "parks@example.test"
check "subject" "$(subject)" "[Park QA] Test email from Change Notifications"
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -d "action=lfwcn_test&_wpnonce=$TN" "$BASE/wp-admin/admin-post.php")
has "a second test within a minute waits" "$LOC" "lfwcn-test=wait"
check "only one test email went out" "$(mails)" "1"
check "a test without a nonce is refused" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -d action=lfwcn_test "$BASE/wp-admin/admin-post.php")" "403"

echo "==> Deactivate and uninstall"
$WP post create --post_status=publish --post_title="Left in the digest" --user=admin >/dev/null
check "a change waits for the digest" "$(waiting)" "1"
$WP plugin deactivate lfw-change-notifications >/dev/null
check "deactivation drops the queue" "$($WP option get lfwcn_queue >/dev/null 2>&1 && echo kept || echo gone)" "gone"
check "deactivation clears scheduled sends" "$($WP cron event list --format=csv --fields=hook | grep -c lfwcn || true)" "0"
check "settings survive deactivation" "$($WP option get lfwcn_settings >/dev/null 2>&1 && echo kept || echo gone)" "kept"
$WP plugin activate lfw-change-notifications >/dev/null
check "reactivation restores the digest" "$($WP eval 'echo wp_get_schedule( LFWCN_Delivery::DIGEST_HOOK );')" "hourly"
$WP plugin deactivate lfw-change-notifications >/dev/null
$WP plugin uninstall lfw-change-notifications --skip-delete >/dev/null
check "uninstall removes the settings" "$($WP option get lfwcn_settings >/dev/null 2>&1 && echo kept || echo gone)" "gone"
check "uninstall leaves no options behind" "$($WP db query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lfwcn\\_%' AND option_name NOT LIKE 'lfwcn\\_test\\_%'" --skip-column-names | tr -d '\r')" "0"
check "uninstall leaves no scheduled events" "$($WP cron event list --format=csv --fields=hook | grep -c lfwcn || true)" "0"

echo
if [ "$FAILED" = 0 ]; then echo "All checks passed."; else echo "Some checks FAILED."; exit 1; fi
