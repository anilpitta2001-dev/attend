<?php
date_default_timezone_set('Asia/Kolkata');
// ── Secure configuration: use Render Environment Variables ────────────────
define('USERNAME',  getenv('BITS_PORTAL_USERNAME') ?: '');
define('PASSWORD',  getenv('BITS_PORTAL_PASSWORD') ?: '');
define('BASE_URL',  getenv('BITS_PORTAL_URL') ?: 'https://balaji.winnou.net');

define('PROXY_URL', getenv('PROXY_URL') ?: '');
define('PROXY_KEY', getenv('PROXY_KEY') ?: '');
define('USE_PROXY', filter_var(getenv('USE_PROXY') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('SESSION_FILE', sys_get_temp_dir() . '/bits_att_session.json');
define('SESSION_TTL', 25 * 60);

// ── Cookie string held in RAM for this request ──
$G_COOKIE_STR = '';

// ── Save session cookie to disk ──
function save_session($cookie_str) {
    file_put_contents(SESSION_FILE, json_encode([
        'cookie' => $cookie_str,
        'saved'  => time(),
    ]), LOCK_EX);
}

// ── Load cached session if still fresh ──
function load_session() {
    if (!file_exists(SESSION_FILE)) return '';
    $d = json_decode(file_get_contents(SESSION_FILE), true);
    if (!$d || empty($d['cookie'])) return '';
    if (time() - ($d['saved'] ?? 0) > SESSION_TTL) return ''; // expired
    return $d['cookie'];
}

// ── Invalidate cached session (force re-login) ──
function clear_session() {
    if (file_exists(SESSION_FILE)) @unlink(SESSION_FILE);
}

// ── Low-level cURL: routes through Cloudflare Worker proxy to bypass IP block ──
function req($url, $post = null, $referer = null) {
    global $G_COOKIE_STR;

    if (USE_PROXY) {
        // ── PROXY MODE: send request through Cloudflare Worker ──────────────────
        // Strip BASE_URL to get just the path+query portion
        $path = str_replace(BASE_URL, '', $url);
        if (!str_starts_with($path, '/')) $path = '/' . $path;

        // Append _key auth param
        $sep       = (strpos($path, '?') !== false) ? '&' : '?';
        $proxy_url = PROXY_URL . $path . $sep . '_key=' . PROXY_KEY;

        $hdrs = ['X-Forward-Cookie: ' . $G_COOKIE_STR];
        if ($referer) {
            $ref_path  = str_replace(BASE_URL, '', $referer);
            $hdrs[]    = 'X-Forward-Referer: ' . $ref_path;
        }
        if ($post) $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $proxy_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'PHP-Proxy/1.0',
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw  = curl_exec($ch);
        $err  = curl_errno($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err || !$raw || $http < 200 || $http >= 400) {
            error_log('Attendance upstream request failed: transport=' . ($err ? curl_strerror($err) : 'none') . ' http=' . $http);
            return ['body' => '', 'loc' => '', 'ok' => false, 'http' => $http, 'curl_error' => $err ? curl_strerror($err) : ''];
        }

        $raw_hdr = substr($raw, 0, $hsz);
        $body    = substr($raw, $hsz);

        // Worker packs all Set-Cookie into one X-Proxy-Cookies header (pipe-separated)
        $proxy_ck = '';
        if (preg_match('/^X-Proxy-Cookies:\s*(.+)$/im', $raw_hdr, $pcm)) $proxy_ck = trim($pcm[1]);
        if ($proxy_ck) {
            foreach (explode(' ||| ', $proxy_ck) as $pair) {
                $pair = trim($pair); if (!$pair) continue;
                [$ck_name] = explode('=', $pair, 2); $ck_name = trim($ck_name);
                $ck_pat = '/(^|;\s*)' . preg_quote($ck_name, '/') . '=[^;]*/i';
                if (preg_match($ck_pat, $G_COOKIE_STR))
                    $G_COOKIE_STR = preg_replace_callback($ck_pat, fn($m) => $m[1] . $pair, $G_COOKIE_STR);
                else
                    $G_COOKIE_STR = $G_COOKIE_STR ? $G_COOKIE_STR . '; ' . $pair : $pair;
            }
        }

        // Worker returns redirect as X-Proxy-Location header
        $loc = '';
        if (preg_match('/^X-Proxy-Location:\s*(.+)$/im', $raw_hdr, $lm)) {
            $loc = trim($lm[1]);
            if ($loc && strpos($loc, 'http') !== 0) $loc = BASE_URL . '/' . ltrim($loc, '/');
        }

        return ['body' => $body, 'loc' => $loc, 'ok' => true, 'http' => $http];

    } else {
        // ── DIRECT MODE: hit college server directly (for Render / trusted IPs) ─
        $ch = curl_init();
        $hdrs = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive',
        ];
        if ($referer) $hdrs[] = 'Referer: ' . $referer;
        if ($G_COOKIE_STR) $hdrs[] = 'Cookie: ' . $G_COOKIE_STR;

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TCP_NODELAY    => true,
        ]);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw    = curl_exec($ch);
        $err    = curl_errno($ch);
        $http   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err || !$raw || $http < 200 || $http >= 400) {
            error_log('Attendance upstream request failed: transport=' . ($err ? curl_strerror($err) : 'none') . ' http=' . $http);
            return ['body' => '', 'loc' => '', 'ok' => false, 'http' => $http, 'curl_error' => $err ? curl_strerror($err) : ''];
        }

        $raw_hdr = substr($raw, 0, $hsz);
        $body    = substr($raw, $hsz);

        preg_match_all('/^Set-Cookie:\s*([^;]+)/im', $raw_hdr, $ck_m);
        foreach ($ck_m[1] as $pair) {
            $pair = trim($pair);
            [$ck_name] = explode('=', $pair, 2); $ck_name = trim($ck_name);
            $ck_pat = '/(^|;\s*)' . preg_quote($ck_name, '/') . '=[^;]*/i';
            if (preg_match($ck_pat, $G_COOKIE_STR))
                $G_COOKIE_STR = preg_replace_callback($ck_pat, fn($m) => $m[1] . $pair, $G_COOKIE_STR);
            else
                $G_COOKIE_STR = $G_COOKIE_STR ? $G_COOKIE_STR . '; ' . $pair : $pair;
        }

        preg_match('/^Location:\s*(.+)/im', $raw_hdr, $loc_m);
        $loc = trim($loc_m[1] ?? '');

        return ['body' => $body, 'loc' => $loc, 'ok' => true, 'http' => $http];
    }
}

// ── Follow up to 6 redirects, carrying cookies ──
function follow($url, $ref = null) {
    for ($i = 0; $i < 6; $i++) {
        $r = req($url, null, $ref);
        if (!$r['ok']) return '';
        if (!$r['loc']) return $r['body'];
        $loc = $r['loc'];
        if (strpos($loc, 'http') !== 0) $loc = BASE_URL . '/' . ltrim($loc, '/');
        $ref = $url;
        $url = $loc;
    }
    return $r['body'] ?? '';
}

function get_csrf($html) {
    // Match any attribute order — type, name, value in any sequence
    // Pattern 1: type=hidden before name
    if (preg_match('/<input[^>]+type=["\']hidden["\'][^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $html, $m)) return $m[1];
    // Pattern 2: name before type
    if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+type=["\']hidden["\'][^>]+value="1"/i', $html, $m)) return $m[1];
    // Pattern 3: value before name
    if (preg_match('/<input[^>]+value="1"[^>]+name="([a-f0-9]{32})"/i', $html, $m)) return $m[1];
    // Pattern 4: just name=32hex anywhere in an input tag (broadest fallback)
    if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]*>/i', $html, $m)) return $m[1];
    return '';
}

// ── Login: reuse cached session if valid, else login fresh ──
function do_login() {
    global $G_COOKIE_STR;

    if (USERNAME === '' || PASSWORD === '') {
        error_log('Attendance portal login is not configured: BITS_PORTAL_USERNAME/PASSWORD missing.');
        return false;
    }
    if (USE_PROXY && PROXY_URL === '') {
        error_log('Attendance proxy is enabled but PROXY_URL is empty.');
        return false;
    }

    // Try cached session first — avoids hammering the portal on every request
    $cached = load_session();
    if ($cached) {
        $G_COOKIE_STR = $cached;
        // Quick verify: check if session is still alive
        $test = req(BASE_URL . '/index.php?option=com_base_studentinfo&Itemid=318');
        if ($test['ok'] && (
            strpos($test['body'], 'Log Out') !== false ||
            strpos($test['body'], 'logout')  !== false ||
            strpos($test['body'], 'Search Criteria') !== false
        )) {
            return true; // session still valid
        }
        // Session dead — clear and re-login
        clear_session();
        $G_COOKIE_STR = '';
    }

    // Fresh login
    $r1 = req(BASE_URL . '/index.php');
    if (!$r1['ok']) return false;

    $home = $r1['body'];
    if ($r1['loc']) {
        $loc = $r1['loc'];
        if (strpos($loc, 'http') !== 0) $loc = BASE_URL . '/' . ltrim($loc, '/');
        $home = follow($loc);
    }

    $csrf = get_csrf($home);

    // If no CSRF on homepage, try dedicated login URLs
    if (!$csrf) {
        foreach ([
            BASE_URL . '/index.php?option=com_user&view=login',
            BASE_URL . '/index.php?option=com_users&view=login',
        ] as $lurl) {
            $rl = req($lurl);
            if (!$rl['ok']) continue;
            $lbody = $rl['body'];
            if ($rl['loc']) {
                $lloc = $rl['loc'];
                if (strpos($lloc, 'http') !== 0) $lloc = BASE_URL . '/' . ltrim($lloc, '/');
                $lbody = follow($lloc);
            }
            $csrf = get_csrf($lbody);
            if ($csrf) { $home = $lbody; break; }
        }
    }

    if (!$csrf) return false;

    // Extract return field
    $return_val = '';
    if      (preg_match('/name="return"\s+value="([^"]+)"/i',   $home, $rm)) $return_val = $rm[1];
    elseif  (preg_match('/value="([^"]+)"\s+name="return"/i',   $home, $rm)) $return_val = $rm[1];
    elseif  (preg_match("/name='return'\s+value='([^']+)'/i",  $home, $rm)) $return_val = $rm[1];
    elseif  (preg_match("/value='([^']+)'\s+name='return'/i",  $home, $rm)) $return_val = $rm[1];

    $r2 = req(BASE_URL . '/index.php', [
        'username' => USERNAME,
        'passwd'   => PASSWORD,
        'option'   => 'com_user',
        'task'     => 'login',
        'return'   => $return_val,
        $csrf      => '1',
    ], BASE_URL . '/index.php');

    if (!$r2['ok']) return false;

    $loc  = $r2['loc'] ?: BASE_URL . '/index.php';
    if (strpos($loc, 'http') !== 0) $loc = BASE_URL . '/' . ltrim($loc, '/');
    $body = follow($loc, BASE_URL . '/index.php');

    // On InfinityFree the response body after login may be minimal —
    // check for any positive login indicator OR absence of login form
    $has_login_form  = strpos($body, 'name="passwd"') !== false || strpos($body, "name='passwd'") !== false;
    $has_login_error = strpos($body, 'username or password') !== false || strpos($body, 'Invalid') !== false;
    $success = !$has_login_form && !$has_login_error && (
           strpos($body, 'Log Out')              !== false
        || strpos($body, 'log-out')              !== false
        || strpos($body, 'logout')               !== false
        || strpos($body, 'Welcome')              !== false
        || strpos($body, 'Search Criteria')      !== false
        || strpos($body, 'com_base_studentinfo') !== false
        || strpos($body, USERNAME)               !== false
        || strlen(trim($body)) > 500  // non-empty page after redirect = likely logged in
    );

    if ($success) save_session($G_COOKIE_STR); // cache the working session

    return $success;
}
function get_csrf_from_page($url, $ref = null) {
    $html = follow($url, $ref);
    if (!$html) return ['csrf' => '', 'html' => ''];
    return ['csrf' => get_csrf($html), 'html' => $html];
}

function search_student($roll) {
    $student_url = BASE_URL . '/index.php?option=com_base_studentinfo&Itemid=318';
    $pg = get_csrf_from_page($student_url, BASE_URL . '/index.php');
    if (!$pg['csrf']) return ['status' => 'session_expired'];

    $search_url = BASE_URL . '/index.php?option=com_base_studentinfo&schoolid=1&Itemid=318';
    $r = req($search_url, [
        'rollnumber'  => $roll,
        'studentname' => '',
        'parentname'  => '',
        'mobile'      => '',
        'search'      => 'Search',
        $pg['csrf']   => '1',
    ], $student_url);
    if (!$r['ok']) return ['status' => 'server_down'];

    $body = $r['body'];
    if ($r['loc']) {
        $loc = $r['loc'];
        if (strpos($loc, 'http') !== 0) $loc = BASE_URL . '/' . ltrim($loc, '/');
        $body = follow($loc, $search_url);
    }
    if (!$body) return ['status' => 'server_down'];
    if (strpos($body, 'Invalid Token') !== false) return ['status' => 'session_expired'];

    preg_match("/getstudata\('(\d+)'/", $body, $uid_m);
    if (!$uid_m) return ['status' => 'not_found'];
    preg_match("/getstudata\('\d+'\)[^>]+>\s*([^<]+)</", $body, $name_m);
    return ['status' => 'ok', 'uid' => $uid_m[1], 'name' => trim($name_m[1] ?? '')];
}

function get_attendance($roll) {
    // ── Rate-limit: max 1 request per roll per 10 seconds (prevents hammering portal) ──
    $rate_file = sys_get_temp_dir() . '/bits_rate_' . preg_replace('/[^a-z0-9]/i', '', $roll) . '.json';
    if (file_exists($rate_file)) {
        $rate_data = json_decode(file_get_contents($rate_file), true);
        if ($rate_data && (time() - ($rate_data['ts'] ?? 0)) < 10) {
            // Return cached result if available, otherwise throttle error
            if (!empty($rate_data['result'])) return $rate_data['result'];
            return ['error' => '⏳ Too many requests. Please wait 10 seconds and try again.'];
        }
    }
    // Save timestamp immediately
    file_put_contents($rate_file, json_encode(['ts' => time(), 'result' => null]), LOCK_EX);

    if (!do_login()) return ['error' => 'The attendance service could not sign in to the college portal. Please try again later; if the issue continues, contact the developer.'];

    $result = search_student($roll);
    if ($result['status'] === 'session_expired') {
        if (!do_login()) return ['error' => 'Your portal session expired and the service could not sign in again. Please try later or contact the developer.'];
        $result = search_student($roll);
    }
    if ($result['status'] === 'server_down') return ['error' => 'The college portal or its connection is temporarily unavailable. Please try again in a few minutes.'];
    if ($result['status'] === 'not_found')   return ['error' => '❌ Student not found. Check roll number: ' . $roll];
    if ($result['status'] !== 'ok')          return ['error' => 'The college portal returned an unexpected response. The developer has been notified in server logs; please try again later.'];

    $uid = $result['uid'];

    // Fetch the detail page. Try format=raw first, then the plain (non-raw) page.
    // We don't just check for the word "Attendance" (it also appears in the nav
    // menu) — we check whether the SPECIFIC attendance table cell can be isolated
    // (see ATT_CELL_RE below), which is the real signal that a fetch succeeded.
    $ATT_CELL_RE = '/<td[^>]*>\s*Attendance\s*<\/td>\s*<td>\s*:\s*<\/td>\s*<td[^>]*>(.*?)<\/td>\s*<\/tr>/si';

    // The college site now requires uid to be submitted as a POST field (via a
    // hidden form #formhdn / JS function getstudata()), NOT as a GET query
    // string parameter. Passing uid in the URL is silently ignored and falls
    // back to a default/blank record. We replicate the real POST here.
    $detail_url = BASE_URL . "/index.php?option=com_base_studentinfo&task=details&Itemid=318&format=raw";
    $plain_url  = BASE_URL . "/index.php?option=com_base_studentinfo&task=details&Itemid=318";
    $referer    = BASE_URL . "/index.php?option=com_base_studentinfo&Itemid=318";
    $post_fields = ['uid' => $uid];

    $detail = '';
    $cell = null;

    $r = req($detail_url, $post_fields, $referer);
    if ($r['ok'] && strlen(trim($r['body'])) >= 100) {
        $detail = $r['body'];
        if (preg_match($ATT_CELL_RE, $detail, $cm)) $cell = $cm[1];
    }

    if ($cell === null) {
        $r2 = req($plain_url, $post_fields, $referer);
        if ($r2['ok'] && strlen(trim($r2['body'])) >= 100) {
            $detail = $r2['body'];
            if (preg_match($ATT_CELL_RE, $detail, $cm)) $cell = $cm[1];
        }
    }

    if ($detail === '') {
        return ['error' => 'The college portal did not return the student details. This may be a temporary portal/proxy issue; please retry in a few minutes.'];
    }

    // Sanity check: make sure the record we got back is actually for this uid,
    // not a stray default (e.g. onclick="...getstudatares('47149',...)" while
    // we asked for uid 51277). If it doesn't match, treat it as not found.
    if ($cell !== null && preg_match("/getstudatares\('(\d+)'/", $cell, $um) && $um[1] !== (string)$uid) {
        $cell = null;
    }

    // ── Parse attendance % and last-attended date ─────────────────────────────
    // Confirmed live structure (from actual saved portal page, July 2026):
    //   <td style="width:100px !important;">Attendance</td><td>:</td><td>
    //     <!--<a href="/">-->
    //       <span ...bar...></span>
    //       <span ...>(70.49) </span>
    //       <span>&nbsp;&nbsp; Last Attended Date : </span>
    //       <span style="color:#0000FF">06-07-2026</span>
    //     <!--</a>-->
    //   </td>
    // Parsing scoped to just this cell ($cell) avoids ever matching an unrelated
    // parenthesised number or date elsewhere on the page (menus, other widgets).
    $attendance = 'Not found';
    $last_date  = 'Not found';

    if ($cell !== null) {
        if (preg_match('/\((\d{1,3}(?:\.\d+)?)\s*\)/', $cell, $m)) {
            $f = (float)$m[1];
            if ($f >= 0 && $f <= 100) $attendance = $m[1];
        }
        if (preg_match('/Last\s*Attended\s*Date\s*:?.{0,200}?(\d{2}-\d{2}-\d{4})/si', $cell, $dm)) {
            $last_date = $dm[1];
        } elseif (preg_match('/(\d{2}-\d{2}-\d{4})/', $cell, $dm)) {
            $last_date = $dm[1];
        }
    }

    // ── Last-resort fallbacks if the cell itself couldn't be isolated (e.g. the
    // college changes the table markup again) — old fuzzy whole-page search ──
    if ($attendance === 'Not found' && preg_match('/Attendance\b.{0,1500}?\((\d{1,3}(?:\.\d+)?)[_\s]*\)/si', $detail, $m)) {
        $f = (float)$m[1];
        if ($f >= 0 && $f <= 100) $attendance = $m[1];
    }
    if ($attendance === 'Not found' && preg_match('/Attendance\b.{0,800}?(\d{1,3}(?:\.\d+)?)\s*%/si', $detail, $m)) {
        $f = (float)$m[1];
        if ($f >= 0 && $f <= 100) $attendance = $m[1];
    }
    if ($last_date === 'Not found' && preg_match('/Last\s*Attended\s*Date.{0,500}?(\d{2}-\d{2}-\d{4})/si', $detail, $dm)) {
        $last_date = $dm[1];
    } elseif ($last_date === 'Not found' && preg_match('/Last\s*Attended.{0,600}?(\d{2}-\d{2}-\d{4})/si', $detail, $dm)) {
        $last_date = $dm[1];
    }

    // ── Debug safety net ────────────────────────────────────────────────────
    // Always dumps the raw detail HTML actually used, plus which source it came
    // from, so you can compare against a known-good page any time instead of
    // guessing blind. Safe to leave on — no credentials in it.
    @file_put_contents(
        sys_get_temp_dir() . '/bits_last_detail_debug.html',
        "<!-- source: " . ($cell !== null && $detail === ($r['body'] ?? null) ? 'format=raw' : ($cell !== null ? 'plain (fallback)' : 'neither matched ATT_CELL_RE')) . " | attendance={$attendance} | last_date={$last_date} -->\n" . $detail,
        LOCK_EX
    );

    if ($attendance === 'Not found') {
        // Don't return a fake success when the portal HTML changes or the record is incomplete.
        error_log('Attendance parse failed for roll=' . $roll . '; portal detail markup did not contain a recognizable attendance value.');
        return ['error' => 'The portal opened, but its attendance data could not be read. The portal layout may have changed; please try later.'];
    }

    // ── Derive branch ─────────────────────────────────────────────────────────
    // Primary: decode branch code from roll number (chars 6–7, e.g. "24C31A66XX" → "66")
    // Fallback: scrape Branch/Department/Course label from portal detail page
    static $BRANCH_MAP = [
        '01' => 'Civil Engineering',
        '02' => 'Electrical & Electronics Engineering',
        '03' => 'Mechanical Engineering',
        '04' => 'Electronics & Communication Engineering',
        '05' => 'Computer Science & Engineering',
        '12' => 'Information Technology',
        '21' => 'Electronics & Instrumentation Engineering',
        '25' => 'Chemical Engineering',
        '42' => 'Computer Science & Engineering (Data Science)',
        '55' => 'Computer Science & Engineering (Cyber Security)',
        '56' => 'Artificial Intelligence & Machine Learning',
        '60' => 'Computer Science & Business Systems',
        '61' => 'Computer Science & Engineering (IoT)',
        '62' => 'Computer Science & Engineering (AIML)',
        '66' => 'CSM — Computer Science & Engineering (AI & ML)',
        '67' => 'Computer Science & Engineering (Data Science)',
        '68' => 'Computer Science & Engineering (Cyber Security)',
        '69' => 'Electronics & Communication Engineering (VLSI)',
    ];

    $branch = '';
    // Try roll number decode first (most reliable — no extra network call)
    if (preg_match('/^\d{2}[A-Z]\d{2}[A-Z](\d{2})/i', $roll, $bm)) {
        $branch = $BRANCH_MAP[$bm[1]] ?? ('Branch Code ' . $bm[1]);
    }
    // Try to scrape branch/dept/course from portal detail page as a better label
    if (preg_match('/(?:Branch|Department|Course|Programme)\s*[:\-]\s*([^\n<|,]{3,60})/i', $detail, $bpm)) {
        $scraped = trim(preg_replace('/\s+/', ' ', strip_tags($bpm[1])));
        if (strlen($scraped) >= 3 && strlen($scraped) <= 80) $branch = $scraped;
    }

    $final = [
        'name'       => $result['name'],
        'roll'       => $roll,
        'attendance' => $attendance,
        'last_date'  => $last_date,
        'branch'     => $branch,
    ];
    // Cache result in rate-limit file (serves repeat hits without portal re-fetch)
    if (isset($rate_file)) file_put_contents($rate_file, json_encode(['ts' => time(), 'result' => $final]), LOCK_EX);
    return $final;
}


// ── JSON API for the Telegram bot ─────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'attendance') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (filter_var(getenv('MAINTENANCE_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Attendance service is temporarily under maintenance. Please try again shortly.', 'maintenance' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expectedKey = getenv('ATTENDANCE_API_KEY') ?: '';
    if ($expectedKey !== '') {
        $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');
        if (!hash_equals($expectedKey, (string)$providedKey)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }

    $apiRoll = strtoupper(trim($_POST['roll'] ?? ''));
    if (!preg_match('/^[0-9A-Z]{8,20}$/', $apiRoll)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid roll number']);
        exit;
    }

    // Shared Supabase suspension guard. This runs BEFORE get_attendance(), so a
    // suspended Roll Number never reaches the college-portal scraper.
    require_once __DIR__ . '/db.php';
    $sdb = attendance_db();
    if (!$sdb) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Database temporarily unavailable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $check = $sdb->prepare('SELECT reason FROM suspended_roll_numbers WHERE roll_number = :roll LIMIT 1');
        $check->execute([':roll' => $apiRoll]);
        $row = $check->fetch();
    } catch (Throwable $e) {
        error_log('Suspension check failed: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Database temporarily unavailable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($row) {
        $suspendReason = (string)($row['reason'] ?? '');
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Roll Number is suspended' . ($suspendReason !== '' ? ': ' . $suspendReason : '')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiResult = get_attendance($apiRoll);
    if (isset($apiResult['error'])) {
        error_log('Attendance API lookup failed: ' . preg_replace('/[^\pL\pN .,:;_\-]/u', '', (string)$apiResult['error']));
        $message = (string)$apiResult['error'];
        $status = 502;
        if (stripos($message, 'wait 10 seconds') !== false) $status = 429;
        if (stripos($message, 'Student not found') !== false) $status = 404;
        if (stripos($message, 'not configured') !== false || stripos($message, 'proxy_url is empty') !== false) $status = 503;
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $message, 'code' => $status], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'data' => $apiResult], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['roll'])) {
    $roll = strtoupper(trim($_POST['roll']));
    $data = get_attendance($roll);
    if (isset($data['error'])) $error = $data['error'];
    else $result = $data;

    // ── Log roll number search ──────────────────────────
    $_sf = __DIR__ . '/data_searches.json';
    $_sd = file_exists($_sf) ? (json_decode(file_get_contents($_sf), true) ?: []) : [];
    if (!isset($_sd['searches'])) $_sd['searches'] = [];
    $_sip = '0.0.0.0';
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $_sk) {
        if (!empty($_SERVER[$_sk])) {
            $_stmp = trim(explode(',', $_SERVER[$_sk])[0]);
            if (filter_var($_stmp, FILTER_VALIDATE_IP)) { $_sip = $_stmp; break; }
        }
    }
    array_unshift($_sd['searches'], [
        'roll'   => $roll,
        'name'   => $result['name'] ?? ($error ? 'ERROR' : ''),
        'att'    => $result['attendance'] ?? '',
        'status' => isset($data['error']) ? 'error' : 'ok',
        'ip'     => $_sip,
        'time'   => time(),
        'device' => preg_match('/Mobile|Android|iPhone/i', $_SERVER['HTTP_USER_AGENT'] ?? '') ? 'Mobile' : 'Desktop',
    ]);
    $_sd['searches'] = array_slice($_sd['searches'], 0, 200);
    file_put_contents($_sf, json_encode($_sd), LOCK_EX);
    unset($_sf, $_sd, $_sip, $_sk, $_stmp);
    // ───────────────────────────────────────────────────
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Attendance Checker — BITS Warangal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg:        #090e18;
      --surface:   #0f1623;
      --surface2:  #141d2e;
      --border:    #1e2d45;
      --border2:   #253550;
      --text:      #dce8f8;
      --muted:     #5a7599;
      --subtle:    #3a5278;
      --accent:    #3d8ef8;
      --accent-glow: rgba(61,142,248,0.18);
      --green:     #22c55e;
      --green-bg:  rgba(34,197,94,0.1);
      --green-bd:  rgba(34,197,94,0.25);
      --yellow:    #f59e0b;
      --yellow-bg: rgba(245,158,11,0.1);
      --yellow-bd: rgba(245,158,11,0.25);
      --red:       #ef4444;
      --red-bg:    rgba(239,68,68,0.1);
      --red-bd:    rgba(239,68,68,0.25);
      --radius:    14px;
      --radius-sm: 9px;
      --font: 'Plus Jakarta Sans', sans-serif;
      --mono: 'JetBrains Mono', monospace;
    }

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: var(--font);
      min-height: 100vh;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      background-image:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(61,142,248,0.07) 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 90% 80%, rgba(34,197,94,0.04) 0%, transparent 50%);
    }

    /* ── Header ── */
    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 28px;
      background: rgba(15,22,35,0.85);
      border-bottom: 1px solid var(--border);
      backdrop-filter: blur(12px);
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .brand { display: flex; align-items: center; gap: 12px; }

    .brand-logo {
      width: 38px; height: 38px;
      background: linear-gradient(135deg, #1a3a6e 0%, #0e2550 100%);
      border: 1px solid var(--border2);
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      box-shadow: 0 0 16px rgba(61,142,248,0.15);
    }

    .brand-name { font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -0.2px; }
    .brand-sub  { font-size: 11px; color: var(--muted); margin-top: 1px; }

    .back-btn {
      display: flex; align-items: center; gap: 6px;
      padding: 8px 16px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: var(--radius-sm);
      color: var(--muted);
      font-size: 13px; font-weight: 500; font-family: var(--font);
      cursor: pointer; text-decoration: none;
      transition: all 0.2s;
    }
    .back-btn:hover { background: var(--border); color: var(--text); border-color: var(--border2); }

    /* ── Main ── */
    main {
      flex: 1;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 40px 16px 60px;
    }

    .page-wrap { width: 100%; max-width: 460px; display: flex; flex-direction: column; gap: 20px; }

    /* ── Card ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 32px 28px;
      box-shadow: 0 4px 32px rgba(0,0,0,0.4);
      animation: fadeUp 0.35s ease both;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .card-eyebrow {
      font-size: 11px; font-weight: 600; letter-spacing: 1px;
      color: var(--accent); text-transform: uppercase; margin-bottom: 8px;
    }

    .card-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 6px; letter-spacing: -0.3px; }
    .card-sub   { font-size: 13.5px; color: var(--muted); margin-bottom: 28px; line-height: 1.55; }

    /* ── Form ── */
    .field-label {
      display: flex; align-items: center; justify-content: space-between;
      font-size: 12px; font-weight: 600; color: var(--subtle);
      text-transform: uppercase; letter-spacing: 0.7px;
      margin-bottom: 9px;
    }
    .field-hint { font-size: 11px; color: var(--muted); text-transform: none; letter-spacing: 0; font-weight: 400; }

    .input-wrap { position: relative; margin-bottom: 16px; }
    .input-icon {
      position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
      font-size: 16px; pointer-events: none; opacity: 0.5;
    }

    input[type="text"] {
      width: 100%;
      padding: 13px 16px 13px 42px;
      background: var(--bg);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      color: var(--text);
      font-size: 15px; font-family: var(--mono); font-weight: 500;
      letter-spacing: 2.5px; text-transform: uppercase;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    input[type="text"]::placeholder { color: var(--subtle); letter-spacing: 1px; font-size: 13px; font-family: var(--font); font-weight: 400; }
    input[type="text"]:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 4px var(--accent-glow);
    }

    .submit-btn {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #1e5fd4 0%, #0e44b0 100%);
      border: 1px solid rgba(61,142,248,0.35);
      border-radius: var(--radius-sm);
      color: #fff;
      font-size: 14px; font-weight: 700; font-family: var(--font); letter-spacing: 0.2px;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 2px 16px rgba(30,95,212,0.35);
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .submit-btn:hover:not(:disabled) {
      background: linear-gradient(135deg, #2468e0 0%, #1350c2 100%);
      box-shadow: 0 4px 24px rgba(30,95,212,0.5);
      transform: translateY(-1px);
    }
    .submit-btn:active:not(:disabled) { transform: translateY(0); }
    .submit-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }

    /* ── Loader ── */
    .loader {
      display: none; align-items: center; justify-content: center;
      gap: 10px; margin-top: 18px;
      font-size: 13px; color: var(--muted);
    }
    .loader.active { display: flex; }
    .spinner {
      width: 16px; height: 16px;
      border: 2px solid var(--border2);
      border-top-color: var(--accent);
      border-radius: 50%;
      animation: spin 0.75s linear infinite;
      flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Error ── */
    .error-box {
      margin-top: 18px;
      padding: 14px 16px;
      background: var(--red-bg);
      border: 1px solid var(--red-bd);
      border-radius: var(--radius-sm);
      font-size: 13px; color: var(--red);
      line-height: 1.55;
      display: flex; gap: 10px; align-items: flex-start;
    }
    .error-icon { flex-shrink: 0; font-size: 15px; margin-top: 1px; }

    /* ── Fallback link ── */
    .fallback-link {
      display: flex; align-items: center; justify-content: center; gap: 7px;
      margin-top: 14px;
      padding: 10px 14px;
      background: rgba(245,158,11,0.06);
      border: 1px solid rgba(245,158,11,0.2);
      border-radius: var(--radius-sm);
      font-size: 12.5px; color: var(--yellow);
      text-decoration: none; font-weight: 500;
      transition: all 0.2s;
    }
    .fallback-link:hover { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.35); }

    /* ── Result Card ── */
    .result-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      animation: fadeUp 0.35s ease both;
      box-shadow: 0 4px 24px rgba(0,0,0,0.35);
    }

    .result-header {
      padding: 14px 20px;
      background: var(--surface2);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .result-header-title { font-size: 11.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.8px; }

    .status-pill {
      font-size: 11px; font-weight: 700;
      padding: 4px 11px; border-radius: 20px;
      letter-spacing: 0.3px;
    }
    .pill-green  { background: var(--green-bg);  color: var(--green);  border: 1px solid var(--green-bd); }
    .pill-yellow { background: var(--yellow-bg); color: var(--yellow); border: 1px solid var(--yellow-bd); }
    .pill-red    { background: var(--red-bg);    color: var(--red);    border: 1px solid var(--red-bd); }

    /* Attendance hero block */
    .att-hero {
      padding: 28px 24px 22px;
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 20px;
    }

    /* Score rectangle box */
    .att-score-box {
      flex-shrink: 0;
      width: 96px; height: 82px;
      border-radius: 12px;
      border: 2px solid;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      gap: 1px;
      position: relative;
      overflow: hidden;
    }
    .att-score-box::before {
      content: '';
      position: absolute; inset: 0;
      opacity: 0.08;
      border-radius: 10px;
    }
    .att-score-box.box-green  { border-color: var(--green);  }
    .att-score-box.box-yellow { border-color: var(--yellow); }
    .att-score-box.box-red    { border-color: var(--red);    }
    .att-score-box.box-green::before  { background: var(--green); }
    .att-score-box.box-yellow::before { background: var(--yellow); }
    .att-score-box.box-red::before    { background: var(--red); }

    .att-number {
      font-size: 26px; font-weight: 800; line-height: 1;
      font-family: var(--mono); letter-spacing: -0.5px;
    }
    .att-pct {
      font-size: 11px; font-weight: 600;
      opacity: 0.7; letter-spacing: 0.5px;
    }
    .att-score-label {
      font-size: 9px; font-weight: 600; letter-spacing: 0.6px;
      text-transform: uppercase; opacity: 0.45; margin-top: 2px;
    }

    .att-green  { color: var(--green); }
    .att-yellow { color: var(--yellow); }
    .att-red    { color: var(--red); }

    .att-info { flex: 1; min-width: 0; }
    .att-info-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .att-info-sub   { font-size: 12px; color: var(--muted); margin-top: 3px; line-height: 1.5; }
    .att-warn {
      display: inline-flex; align-items: center; gap: 5px;
      margin-top: 10px; padding: 5px 10px;
      background: var(--red-bg); border: 1px solid var(--red-bd);
      border-radius: 6px; font-size: 11px; color: var(--red); font-weight: 600;
    }

    /* Info rows */
    .info-rows { padding: 6px 0; }
    .info-row {
      display: flex; justify-content: space-between; align-items: center;
      padding: 13px 22px;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
      transition: background 0.15s;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row:hover { background: rgba(255,255,255,0.02); }
    .info-label {
      display: flex; align-items: center; gap: 8px;
      color: var(--muted); font-weight: 500;
    }
    .info-icon { font-size: 14px; opacity: 0.7; }
    .info-value { color: var(--text); font-weight: 600; text-align: right; max-width: 55%; word-break: break-word; }

    /* ── Footer ── */
    footer {
      background: var(--surface);
      border-top: 1px solid var(--border);
      padding: 22px 28px 18px;
    }
    .footer-top {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 20px; margin-bottom: 16px; flex-wrap: wrap;
    }
    .footer-note { font-size: 12px; color: var(--muted); line-height: 1.65; max-width: 480px; }
    .footer-links { display: flex; gap: 18px; flex-wrap: wrap; align-items: center; }
    .footer-links a { font-size: 12px; color: var(--muted); text-decoration: none; transition: color 0.2s; }
    .footer-links a:hover { color: var(--text); }
    .footer-bottom {
      padding-top: 14px; border-top: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .footer-copy { font-size: 11px; color: var(--subtle); }
    .footer-badges { display: flex; gap: 8px; }
    .f-badge {
      font-size: 11px; color: var(--muted);
      border: 1px solid var(--border);
      border-radius: 6px; padding: 4px 10px;
      display: flex; align-items: center; gap: 5px;
      background: var(--surface2);
    }

    /* ── Live counter pill ── */
    .live-pill {
      position: fixed; bottom: 84px; right: 16px;
      background: var(--surface);
      border: 1px solid var(--border);
      color: var(--muted); font-size: 12px; font-family: var(--font);
      padding: 8px 14px; border-radius: 20px;
      text-decoration: none; display: flex; align-items: center; gap: 7px;
      z-index: 999;
      box-shadow: 0 4px 18px rgba(0,0,0,0.5);
      transition: all 0.2s;
    }
    .live-pill:hover { background: var(--surface2); color: var(--text); border-color: var(--border2); }
    .live-dot {
      width: 7px; height: 7px;
      background: var(--green); border-radius: 50%;
      animation: pulse 1.6s infinite;
    }
    @keyframes pulse {
      0%,100% { opacity: 1; transform: scale(1); }
      50%      { opacity: 0.4; transform: scale(1.45); }
    }

    /* ── Responsive ── */
    @media (max-width: 520px) {
      header  { padding: 12px 16px; }
      .card   { padding: 24px 20px; }
      footer  { padding: 18px 16px; }
      .footer-top { flex-direction: column; }
      .att-hero { padding: 22px 18px 18px; }
    }

    /* ── Tools Banner ── */
    .tools-banner {
      margin-top: 10px;
    }
    .tools-banner-heading {
      display: flex; align-items: center; gap: 10px;
      margin-bottom: 12px;
    }
    .tools-banner-heading::before,
    .tools-banner-heading::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }
    .tools-banner-label {
      font-size: 10.5px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--muted);
      white-space: nowrap;
    }
    .tools-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .tool-card {
      display: flex; flex-direction: column;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px 16px 16px;
      text-decoration: none;
      transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
      position: relative; overflow: hidden;
    }
    .tool-card::before {
      content: '';
      position: absolute; top: 0; left: 0; right: 0; height: 2px;
      border-radius: var(--radius) var(--radius) 0 0;
      transition: opacity 0.2s;
    }
    .tool-card.tc-blue::before  { background: linear-gradient(90deg, var(--accent), #5aa8ff); }
    .tool-card.tc-purple::before { background: linear-gradient(90deg, #a78bfa, #7c3aed); }
    .tool-card:hover { border-color: var(--border2); transform: translateY(-2px); box-shadow: 0 6px 24px rgba(0,0,0,0.4); }
    .tool-card-icon {
      font-size: 22px; margin-bottom: 10px; line-height: 1;
    }
    .tool-card-name {
      font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 5px; line-height: 1.2;
    }
    .tool-card-desc {
      font-size: 11.5px; color: var(--muted); line-height: 1.55; flex: 1;
    }
    .tool-card-cta {
      display: inline-flex; align-items: center; gap: 5px;
      margin-top: 12px; font-size: 11px; font-weight: 700;
      letter-spacing: 0.4px; text-transform: uppercase;
    }
    .tool-card.tc-blue  .tool-card-cta { color: var(--accent); }
    .tool-card.tc-purple .tool-card-cta { color: #a78bfa; }
    .tool-card-cta::after { content: '→'; transition: transform 0.2s; }
    .tool-card:hover .tool-card-cta::after { transform: translateX(3px); }
    .tool-card-badge {
      position: absolute; top: 10px; right: 10px;
      font-size: 9px; font-weight: 700; letter-spacing: 0.5px;
      padding: 2px 7px; border-radius: 20px; text-transform: uppercase;
    }
    .badge-new { background: rgba(61,142,248,0.15); color: var(--accent); border: 1px solid rgba(61,142,248,0.3); }
    .badge-free { background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.25); }
    @media (max-width: 400px) {
      .tools-grid { grid-template-columns: 1fr; }
    }

    /* ── News Ticker ── */
    .ticker-wrap {
      width: 100%;
      background: linear-gradient(90deg, #0a1628 0%, #0d1d35 50%, #0a1628 100%);
      border-bottom: 1px solid var(--border);
      overflow: hidden;
      display: flex;
      align-items: center;
      height: 34px;
      position: relative;
      z-index: 49;
    }
    .ticker-label {
      flex-shrink: 0;
      background: var(--accent);
      color: #fff;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      padding: 0 14px;
      height: 100%;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      z-index: 2;
    }
    .ticker-label::after {
      content: '';
      position: absolute;
      left: calc(var(--label-w, 90px) - 1px);
      top: 0; bottom: 0;
      width: 18px;
      background: linear-gradient(90deg, var(--accent), transparent);
      pointer-events: none;
    }
    .ticker-track {
      flex: 1;
      overflow: hidden;
      height: 100%;
      display: flex;
      align-items: center;
      mask-image: linear-gradient(90deg, transparent 0%, black 3%, black 97%, transparent 100%);
      -webkit-mask-image: linear-gradient(90deg, transparent 0%, black 3%, black 97%, transparent 100%);
    }
    .ticker-inner {
      display: flex;
      align-items: center;
      gap: 0;
      white-space: nowrap;
      animation: ticker-scroll 38s linear infinite;
      will-change: transform;
    }
    .ticker-inner:hover { animation-play-state: paused; }
    @keyframes ticker-scroll {
      0%   { transform: translateX(0); }
      100% { transform: translateX(-50%); }
    }
    .ticker-item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 500;
      color: #8ba8cc;
      padding: 0 28px 0 0;
    }
    .ticker-item .t-dot {
      width: 4px; height: 4px;
      border-radius: 50%;
      background: var(--accent);
      flex-shrink: 0;
      opacity: 0.6;
    }
    .ticker-item.t-alert  { color: #f87171; }
    .ticker-item.t-alert  .t-dot { background: #f87171; opacity: 1; }
    .ticker-item.t-notice { color: #fbbf24; }
    .ticker-item.t-notice .t-dot { background: #fbbf24; opacity: 1; }
    .ticker-item.t-info   { color: #7dd3fc; }
    .ticker-item.t-info   .t-dot { background: #7dd3fc; opacity: 1; }
    .ticker-item.t-ok     { color: #4ade80; }
    .ticker-item.t-ok     .t-dot { background: #4ade80; opacity: 1; }
    .ticker-sep {
      color: var(--border2);
      padding: 0 10px 0 0;
      font-size: 14px;
      opacity: 0.4;
    }
  </style>
</head>
<body>

<header>
  <div class="brand">
    <div class="brand-logo">📊</div>
    <div>
      <div class="brand-name">Attendance Checker</div>
      <div class="brand-sub">Balaji Institute of Technology &amp; Science</div>
    </div>
  </div>
  <span class="back-btn" style="cursor:default;">Telegram Enabled</span>
</header>

<!-- ── News Ticker ── -->
<div class="ticker-wrap">
  <div class="ticker-label">
    <span>📢</span> Updates
  </div>
  <div class="ticker-track">
    <div class="ticker-inner" id="tickerInner">
      <!-- Items duplicated for seamless loop -->
      <span class="ticker-item t-alert"><span class="t-dot"></span>B.Tech 2nd Year 2nd Sem attendance marking stopped — check portal for latest status</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-notice"><span class="t-dot"></span>Mid-2 examination schedule released — verify your attendance eligibility before hall ticket</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-info"><span class="t-dot"></span>Attendance below 75%? Apply for condonation at the Academic Section before the deadline</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-ok"><span class="t-dot"></span>This is an unofficial tool — always cross-check with the official BITS portal</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-notice"><span class="t-dot"></span>Lab attendance is counted separately — ensure both theory &amp; lab are above 75%</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-info"><span class="t-dot"></span>For discrepancies in attendance, contact your class teacher or academic coordinator</span>
      <span class="ticker-sep">◆</span>
      <!-- Duplicate for seamless infinite scroll -->
      <span class="ticker-item t-alert"><span class="t-dot"></span>B.Tech 2nd Year 2nd Sem attendance marking stopped — check portal for latest status</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-notice"><span class="t-dot"></span>Mid-2 examination schedule released — verify your attendance eligibility before hall ticket</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-info"><span class="t-dot"></span>Attendance below 75%? Apply for condonation at the Academic Section before the deadline</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-ok"><span class="t-dot"></span>This is an unofficial tool — always cross-check with the official BITS portal</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-notice"><span class="t-dot"></span>Lab attendance is counted separately — ensure both theory &amp; lab are above 75%</span>
      <span class="ticker-sep">◆</span>
      <span class="ticker-item t-info"><span class="t-dot"></span>For discrepancies in attendance, contact your class teacher or academic coordinator</span>
      <span class="ticker-sep">◆</span>
    </div>
  </div>
</div>

<main>
  <div class="page-wrap">

    <div class="card">
      <div class="card-eyebrow">Student Portal</div>
      <div class="card-title">Check Your Attendance</div>
      <div class="card-sub">Enter your roll number below to instantly fetch your attendance from the college portal.</div>

      <form method="POST" onsubmit="handleSubmit(event)">
        <div class="field-label">
          Roll Number
          <span class="field-hint">e.g. 24C31A66XX</span>
        </div>
        <div class="input-wrap">
          <span class="input-icon">🎓</span>
          <input type="text" id="roll" name="roll"
                 placeholder="Enter your roll number"
                 value="<?= htmlspecialchars($_POST['roll'] ?? '') ?>"
                 required autocomplete="off" spellcheck="false">
        </div>
        <button type="submit" class="submit-btn" id="submitBtn">
          <span id="btnIcon">🔍</span>
          <span id="btnText">Check Attendance</span>
        </button>
      </form>

      <div class="loader" id="loader">
        <div class="spinner"></div>
        Fetching from college portal…
      </div>

      <?php if ($error): ?>
        <div class="error-box">
          <span class="error-icon">⚠️</span>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
        <a href="https://attendancechecker-o41l.onrender.com/" class="fallback-link" target="_blank" rel="noopener">
          🧭 Try the backup portal instead
        </a>
      <?php endif; ?>
    </div>

    <?php if ($result): ?>
      <?php
        // Use the raw server value exactly as returned — no re-rounding via number_format
        $att_raw     = $result['attendance'];             // e.g. "83.43" or "83" — no % suffix
        $att_num     = floatval($att_raw);
        $att_display = $att_raw;                          // show EXACTLY what official portal shows
        if ($att_num >= 90)     { $cls = 'green';  $pill = 'pill-green';  $status = '✓ Excellent';       $desc = 'Well above the 75% requirement. Keep it up!'; }
        elseif ($att_num >= 75) { $cls = 'green';  $pill = 'pill-green';  $status = '✓ Satisfactory';    $desc = 'Above the 75% minimum requirement.'; }
        elseif ($att_num >= 40) { $cls = 'yellow'; $pill = 'pill-yellow'; $status = '⚠ Needs Attention'; $desc = 'Below 75% — improvement needed.'; }
        else                    { $cls = 'red';    $pill = 'pill-red';    $status = '✕ Critical';         $desc = 'Severely below required attendance.'; }
      ?>
      <div class="result-card">
        <div class="result-header">
          <span class="result-header-title">📋 Attendance Report</span>
          <span class="status-pill <?= $pill ?>"><?= $status ?></span>
        </div>

        <div class="att-hero">
          <div class="att-score-box box-<?= $cls ?>">
            <span class="att-number att-<?= $cls ?>"><?= $att_display ?></span>
            <span class="att-pct att-<?= $cls ?>">%</span>
            <span class="att-score-label att-<?= $cls ?>">Score</span>
          </div>
          <div class="att-info">
            <div class="att-info-title">Overall Attendance</div>
            <div class="att-info-sub"><?= $desc ?></div>
            <?php if ($att_num < 75): ?>
              <div class="att-warn">⚠ Below 75% minimum requirement</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="info-rows">
          <div class="info-row">
            <span class="info-label"><span class="info-icon">👤</span>Student Name</span>
            <span class="info-value"><?= htmlspecialchars($result['name']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label"><span class="info-icon">🎓</span>Roll Number</span>
            <span class="info-value" style="font-family:var(--mono);letter-spacing:1.5px;font-size:12.5px;"><?= htmlspecialchars($result['roll']) ?></span>
          </div>
          <?php if (!empty($result['branch'])): ?>
          <div class="info-row">
            <span class="info-label"><span class="info-icon">🏫</span>Branch</span>
            <span class="info-value"><?= htmlspecialchars($result['branch']) ?></span>
          </div>
          <?php endif; ?>
          <div class="info-row">
            <span class="info-label"><span class="info-icon">📅</span>Last Attended</span>
            <span class="info-value"><?= htmlspecialchars($result['last_date']) ?></span>
          </div>
        </div>
      </div>
    <?php endif; ?>


  </div>
</main>

<footer>
  <div class="footer-top">
    <p class="footer-note">
      🔒 Attendance is fetched live from the official college portal and shown only to you.
      Attendance is fetched live when requested. Telegram automation uses your configured roll number.
      Portal credentials stay on the server in Render Environment Variables.
      This is an unofficial student utility tool.
    </p>
    <div class="footer-links">
      <a href="#">Privacy Policy</a>
      <a href="#">Terms of Use</a>
      <a href="#">Disclaimer</a>
      <a href="mailto:Bitsresourcesr22@gmail.com">Contact</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span class="footer-copy">© <?= date('Y') ?> BITS Attendance Checker · Not affiliated with BITS Warangal officially.</span>
    <div class="footer-badges">
      <span class="f-badge">🔒 No Data Stored</span>
      <span class="f-badge">👁 Read Only</span>
    </div>
  </div>
</footer>

</body>
</html>
