#!/usr/bin/env bash
#
# Knowledge Forge — manual source deployment to production.
#
# Replaces hand-dragging files in FileZilla with one reviewable, repeatable operation:
#
#     ./deploy.sh              # preview only; nothing on the server is touched
#     ./deploy.sh deploy       # the real thing, after an interactive confirmation
#     ./deploy.sh --help
#
# ## What it does, and the one thing it must never do
#
# It synchronises exactly one directory — this checkout's `src/` — into exactly one directory on one
# server. That server hosts several other applications and several sibling copies of this one
# (`joymeal.com`, `joymeal.local`, `joymeal.seawolf.io.old`), so the destination is written down once,
# as a literal, and verified three ways before a single byte moves: as a string here, by its resolved
# physical path on the server, and by application fingerprints inside it. There is no flag, argument or
# environment variable that can point this script somewhere else. Redirecting it requires editing it.
#
# ## Deletion
#
# Removing a file locally removes it from production — that is the point of `--delete`, and it is also
# the dangerous part. Four things stand in the way of a bad one: deletions are listed individually in
# every preview, they are capped (`--max-delete`), they happen *after* the transfer succeeds
# (`--delete-delay`), and every deleted or overwritten file is first moved into a timestamped backup
# directory belonging to this application alone. `--delete-excluded` is never used, so an excluded
# server-side artefact is protected rather than destroyed.
#
# ## What it deliberately does not do
#
# No composer, no migrations, no cache clearing, no service restarts, no queue or worker commands, no
# ownership or permission changes, and nothing at all outside `src/`. Those are separate decisions with
# separate consequences; this script reports when one of them is needed and stops.

set -euo pipefail

# ---------------------------------------------------------------------------------- configuration
#
# Deliberately literal and not overridable. See the header.

readonly APP_NAME='knowledge-forge'
readonly SSH_HOST='joymeal'
readonly REMOTE_ROOT='/opt/web/sites/joymeal.seawolf.io/knowledge-forge'
readonly REMOTE_SRC="${REMOTE_ROOT}/src"
readonly REMOTE_BACKUPS="${REMOTE_ROOT}/backups/deploy"
readonly CONFIRM_WORD='DEPLOY'

# A local tree with fewer source files than this is a broken checkout, not a deployment. Measured at
# 1197 .php files on 2026-10-14; the floor is deliberately far below that and still far above zero.
readonly MIN_SOURCE_FILES=500

# More deletions than this stops the run. `rsync` enforces it too, so the cap holds even if the
# counting below is ever wrong.
MAX_DELETE="${KF_MAX_DELETE:-25}"

# Never transferred — and, because `--delete-excluded` is never passed, never deleted on the server
# either. This is what protects the server-side artefacts that someone dropped into `src/` by hand.
readonly -a EXCLUDES=(
    '*.zip' '*.tar' '*.tar.gz' '*.tgz'
    '*.bak' '*.bak_*' '*.orig' '*.rej'
    '*.log' '*~' '*.swp' '*.swo'
    '.DS_Store' 'Thumbs.db'
    '.git/' '.gitignore' '.idea/' '.vscode/' 'nbproject/'
    '__pycache__/' '*.pyc'
)

# Found in production `src/` on 2026-10-14 and absent locally. Named explicitly as well as covered by
# the patterns above, so the protection is visible to whoever reads this next.
readonly -a PROTECT_REMOTE=(
    'AudioToTextOld.zip'
)

# Everything outside `src/` that the code inside `src/` depends on: the route table its templates
# generate URLs from, the container bindings its actions are built from, the stylesheets and scripts its
# pages behave by, and the dependency list it is autoloaded through. A route name that is not in the
# deployed route table does not degrade — `UrlGenerator` throws, and the page 500s. So these two trees
# are compared in full, every run, rather than from a hand-kept list of filenames that would go stale.
#
# They are never transferred by this script. Differences are reported, and they block `deploy` unless
# KF_ALLOW_SRC_ONLY=1 says the release has been judged safe without them.
readonly -a COUPLED_DIRS=(
    'config'
    'assets'
)

# Server-side editor and backup droppings, skipped so they do not show up as "differences".
readonly -a COUPLED_SKIP=(
    '!' '-name' '*.bak' '!' '-name' '*.bak_*' '!' '-name' '*~'
    '!' '-name' '*.swp' '!' '-name' '*.orig'
)

readonly -a SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=15)

# ---------------------------------------------------------------------------------- output helpers

if [[ -t 1 ]]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_DIM=$'\033[2m'
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_DIM=''
fi
readonly C_RESET C_BOLD C_RED C_GREEN C_YELLOW C_DIM

info()  { printf '%s\n' "$*"; }
step()  { printf '%s==>%s %s\n' "$C_BOLD" "$C_RESET" "$*"; }
ok()    { printf '%s  ok%s   %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()  { printf '%s  warn%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
rule()  { printf '========================================\n'; }

die() {
    printf '\n%sdeployment refused:%s %s\n' "$C_RED$C_BOLD" "$C_RESET" "$1" >&2
    shift || true
    for line in "$@"; do printf '  %s\n' "$line" >&2; done
    exit 1
}

usage() {
    cat <<USAGE
${APP_NAME} — manual source deployment

Usage:
  ./deploy.sh                 Preview (dry run). Nothing on the server is modified.
  ./deploy.sh deploy          Deploy for real, after typing ${CONFIRM_WORD} to confirm.
  ./deploy.sh --help          This text.

Synchronises:
  LOCAL   <this checkout>/src/
  REMOTE  ${SSH_HOST}:${REMOTE_SRC}/

Only that directory. Nothing else on the server is read or written.

Environment knobs (both only relax a secondary check; neither can move the destination):
  KF_MAX_DELETE=N        Raise the deletion cap for one run (default ${MAX_DELETE}).
  KF_ALLOW_SRC_ONLY=1    Proceed even though coupled files outside src/ differ.

Recovery: every overwritten or deleted file is moved, before being replaced, into
  ${REMOTE_BACKUPS}/<timestamp>/
on the server, preserving its path. Restore by copying it back.
USAGE
}

# ---------------------------------------------------------------------------------- local checks

resolve_project_root() {
    local self="${BASH_SOURCE[0]}"

    # So the script works from any working directory, and keeps working if it is symlinked onto a PATH.
    if command -v readlink >/dev/null 2>&1; then
        self="$(readlink -f -- "$self" 2>/dev/null || printf '%s' "${BASH_SOURCE[0]}")"
    fi

    cd -- "$(dirname -- "$self")" && pwd -P
}

check_local() {
    step 'Local checkout'

    [[ -f "${PROJECT_ROOT}/composer.json" ]] \
        || die "no composer.json in ${PROJECT_ROOT} — this is not the ${APP_NAME} project root."

    # The autoload mapping is the claim that `src` really is this application's source root. Deploying
    # `src/` from a project that maps its namespace somewhere else would put files in the wrong place.
    grep -q '"App\\\\": *"src"' "${PROJECT_ROOT}/composer.json" \
        || die 'composer.json does not map App\ to src/ — refusing to guess the source directory.'

    [[ -e "$LOCAL_SRC" ]] || die "missing local source directory: ${LOCAL_SRC}"
    [[ -d "$LOCAL_SRC" ]] || die "local source path is not a directory: ${LOCAL_SRC}"
    [[ -L "${LOCAL_SRC%/}" ]] && die "local source directory is a symlink: ${LOCAL_SRC}"

    local count
    count="$(find "$LOCAL_SRC" -type f -name '*.php' | wc -l)"

    (( count >= MIN_SOURCE_FILES )) || die \
        "only ${count} PHP files under ${LOCAL_SRC} (expected at least ${MIN_SOURCE_FILES})." \
        'A partial checkout would propose deleting most of production. Nothing was sent.'

    local links
    links="$(find "$LOCAL_SRC" -type l | wc -l)"

    if (( links > 0 )); then
        warn "${links} symlink(s) under src/; any pointing outside the tree are skipped (--safe-links)."
    fi

    for tool in rsync ssh; do
        command -v "$tool" >/dev/null 2>&1 || die "${tool} is not installed locally."
    done

    ok "${PROJECT_ROOT} (${count} PHP files under src/)"
}

# The destination, checked as text before it is ever used as a path. Every clause here is one way a
# deletion could escape the application: an empty variable, a shared parent, a relative segment, or a
# trailing slash that turns a directory into its contents.
check_destination_string() {
    step 'Destination safety'

    local dest="$REMOTE_SRC"

    [[ -n "$dest" ]]            || die 'the remote destination is empty.'
    [[ "$dest" == /* ]]         || die "the remote destination is not absolute: ${dest}"
    [[ "$dest" != *'..'* ]]     || die "the remote destination contains '..': ${dest}"
    [[ "$dest" != *'//'* ]]     || die "the remote destination contains '//': ${dest}"
    [[ "$dest" != */ ]]         || die "the remote destination has a trailing slash: ${dest}"

    case "$dest" in
        /|/opt|/opt/web|/opt/web/sites|/var|/var/www|/var/www/html|/home|/etc|/usr|/srv)
            die "refusing to deploy to a shared or system directory: ${dest}" ;;
    esac

    [[ "$dest" == "${REMOTE_ROOT}/src" ]] \
        || die "the destination is not this application's src/: ${dest}"

    # Belt and braces: the literal, spelled out once more. If someone edits the constants above and
    # gets it wrong, this is the line that catches it.
    [[ "$dest" == '/opt/web/sites/joymeal.seawolf.io/knowledge-forge/src' ]] \
        || die "the destination does not match the approved path: ${dest}"

    [[ "${#dest}" -gt "${#REMOTE_ROOT}" ]] \
        || die 'the destination is not strictly inside the application root.'

    ok "${dest}"
    ok "deletions confined to that directory; --delete-excluded is never used"
}

# ---------------------------------------------------------------------------------- remote checks

# One round trip, no interpolation: the remote script is a quoted heredoc and the paths arrive as
# arguments, so nothing local can be spliced into a remote command line.
remote_facts() {
    ssh "${SSH_OPTS[@]}" "$SSH_HOST" bash -s -- "$REMOTE_ROOT" <<'REMOTE'
set -u
root="$1"
src="${root}/src"

printf 'user=%s\n' "$(whoami)"
printf 'host=%s\n' "$(hostname)"
printf 'rsync=%s\n' "$(command -v rsync || echo '')"
printf 'tar=%s\n'   "$(command -v tar || echo '')"
printf 'root_exists=%s\n' "$([ -d "$root" ] && echo 1 || echo 0)"
printf 'src_exists=%s\n'  "$([ -d "$src" ] && echo 1 || echo 0)"
printf 'src_is_link=%s\n' "$([ -L "$src" ] && echo 1 || echo 0)"
printf 'src_writable=%s\n' "$([ -w "$src" ] && echo 1 || echo 0)"
printf 'src_real=%s\n' "$([ -d "$src" ] && (cd "$src" && pwd -P) || echo '')"
printf 'has_env=%s\n' "$([ -f "${src}/Environment.php" ] && echo 1 || echo 0)"
printf 'has_composer=%s\n' "$([ -f "${root}/composer.json" ] && echo 1 || echo 0)"
printf 'src_files=%s\n' "$([ -d "$src" ] && find "$src" -type f -name '*.php' | wc -l || echo 0)"
printf 'free_kb=%s\n' "$(df -Pk "$root" | awk 'NR==2 {print $4}')"
REMOTE
}

fact() { printf '%s\n' "$REMOTE_INFO" | sed -n "s/^$1=//p"; }

check_remote() {
    step "Remote target (${SSH_HOST})"

    ssh "${SSH_OPTS[@]}" "$SSH_HOST" true 2>/dev/null \
        || die "cannot reach ${SSH_HOST} over SSH." \
            'Check: ssh -v joymeal' \
            'The host, user, port and key come from ~/.ssh/config; this script changes none of them.'

    REMOTE_INFO="$(remote_facts)" || die "the remote inspection failed on ${SSH_HOST}."

    [[ "$(fact root_exists)" == '1' ]] || die "remote application root not found: ${REMOTE_ROOT}"
    [[ "$(fact src_exists)"  == '1' ]] || die "remote source directory not found: ${REMOTE_SRC}"
    [[ "$(fact src_is_link)" == '0' ]] || die "remote src/ is a symlink: ${REMOTE_SRC}"

    # The physical path, resolved on the server. This is what catches a symlinked *component* — a
    # `joymeal.seawolf.io` that quietly points at `joymeal.seawolf.io.old`, for instance.
    local real
    real="$(fact src_real)"
    [[ "$real" == "$REMOTE_SRC" ]] \
        || die "remote src/ resolves elsewhere: ${real}" "expected: ${REMOTE_SRC}"

    [[ "$(fact src_writable)" == '1' ]] \
        || die "$(fact user) cannot write to ${REMOTE_SRC}." \
            'Report this rather than changing ownership: a chown here affects a shared server.'

    [[ -n "$(fact rsync)" ]] || die 'rsync is not installed on the server.'

    # Fingerprints. A directory that is writable and named src/ is not yet proof it is this
    # application; these two files are.
    [[ "$(fact has_env)" == '1' && "$(fact has_composer)" == '1' ]] \
        || die "${REMOTE_SRC} does not look like ${APP_NAME} (no src/Environment.php or composer.json)."

    ok "$(fact user)@$(fact host)"
    ok "${real} (writable, $(fact src_files) PHP files)"
    ok "rsync $(ssh "${SSH_OPTS[@]}" "$SSH_HOST" 'rsync --version | head -1' | awk '{print $3}')"
}

# Changes outside src/ that src/ depends on, compared in one read-only round trip.
#
# Two fingerprints per file, not one. The exact checksum says whether the bytes match; a second
# checksum, taken after collapsing runs of whitespace, says whether the *code* matches. Production's
# JavaScript turned out to be indented two spaces where this checkout indents four — identical
# statement for statement, different byte for byte. That is worth saying out loud and not worth
# blocking a release over, whereas a real difference in those files is worth both.
#
# An earlier version of this function took its file list over ssh's stdin, which `bash -s` had already
# consumed, so every file came back "not comparable" and the check silently passed. The server now
# walks the trees itself and needs no stdin at all.
checksum_tree() {
    find "${COUPLED_DIRS[@]}" -type f "${COUPLED_SKIP[@]}" -print0 2>/dev/null \
        | while IFS= read -r -d '' f; do
            printf '%s\t%s\t%s\n' \
                "$f" \
                "$(md5sum "$f" | cut -d' ' -f1)" \
                "$(sed -e 's/[[:space:]]\{1,\}/ /g' -e 's/^ //' -e 's/ $//' "$f" \
                    | md5sum | cut -d' ' -f1)"
        done \
        | LC_ALL=C sort
}

check_coupled_files() {
    step "Files outside src/ that this release depends on (${COUPLED_DIRS[*]})"

    local -a missing_dirs=()
    local d

    for d in "${COUPLED_DIRS[@]}"; do
        [[ -d "${PROJECT_ROOT}/${d}" ]] || missing_dirs+=("$d")
    done

    if (( ${#missing_dirs[@]} > 0 )); then
        warn "not present locally, so not compared: ${missing_dirs[*]}"

        return 0
    fi

    local local_sums remote_sums
    local_sums="$(cd "$PROJECT_ROOT" && checksum_tree)"

    # The same walk, on the server. Spelled out again rather than shipped over, because sending a
    # script to run on a production host is a bigger thing than reading two directories.
    remote_sums="$(
        ssh "${SSH_OPTS[@]}" "$SSH_HOST" bash -s -- "$REMOTE_ROOT" "${COUPLED_DIRS[@]}" <<'REMOTE'
set -u
cd "$1" || exit 1
shift
find "$@" -type f ! -name '*.bak' ! -name '*.bak_*' ! -name '*~' ! -name '*.swp' ! -name '*.orig' \
    -print0 2>/dev/null \
    | while IFS= read -r -d '' f; do
        printf '%s\t%s\t%s\n' \
            "$f" \
            "$(md5sum "$f" | cut -d' ' -f1)" \
            "$(sed -e 's/[[:space:]]\{1,\}/ /g' -e 's/^ //' -e 's/ $//' "$f" | md5sum | cut -d' ' -f1)"
    done \
    | LC_ALL=C sort
REMOTE
    )" || die 'could not read config/ and assets/ on the server.'

    declare -A their_exact=() their_norm=()
    local path exact norm

    while IFS=$'\t' read -r path exact norm; do
        [[ -n "$path" ]] || continue
        their_exact["$path"]="$exact"
        their_norm["$path"]="$norm"
    done <<< "$remote_sums"

    COUPLED_DIFFS=()
    COUPLED_COSMETIC=()
    local -i same=0

    while IFS=$'\t' read -r path exact norm; do
        [[ -n "$path" ]] || continue

        if [[ -z "${their_exact[$path]+set}" ]]; then
            COUPLED_DIFFS+=("${path} — absent on the server")
        elif [[ "${their_norm[$path]}" != "$norm" ]]; then
            COUPLED_DIFFS+=("${path} — content differs")
        elif [[ "${their_exact[$path]}" != "$exact" ]]; then
            COUPLED_COSMETIC+=("${path} — same code, different whitespace")
        else
            same+=1
        fi

        unset "their_exact[$path]" "their_norm[$path]"
    done <<< "$local_sums"

    # Whatever is left belongs only to the server. Reported, never touched: this script writes nothing
    # outside src/.
    for path in "${!their_exact[@]}"; do
        COUPLED_DIFFS+=("${path} — only on the server")
    done

    ok "${same} file(s) identical on both sides"

    if (( ${#COUPLED_COSMETIC[@]} > 0 )); then
        warn "${#COUPLED_COSMETIC[@]} file(s) differ only in formatting (harmless, not deployed by this script):"
        print_capped "${COUPLED_COSMETIC[@]}"
    fi

    if (( ${#COUPLED_DIFFS[@]} > 0 )); then
        warn "${#COUPLED_DIFFS[@]} file(s) outside src/ really differ from production:"
        print_capped "${COUPLED_DIFFS[@]}"
        warn 'Deploying only src/ while these differ ships an incomplete release.'
    fi
}

# Long lists are noise; the count is the part that matters.
print_capped() {
    local -i shown=0
    local item

    for item in "$@"; do
        printf '         %s\n' "$item" >&2
        shown+=1

        if (( shown >= 15 && $# > 15 )); then
            printf '         ... and %d more\n' "$(( $# - shown ))" >&2
            break
        fi
    done
}

# ---------------------------------------------------------------------------------- rsync

# `--checksum` rather than the default size-and-time check, because this tree was previously uploaded
# by hand: every file's timestamp differs while its contents do not, and a timestamp is not a change
# worth sending. The cost is reading 9 MB on each side; the gain is a preview that lists only files
# whose contents really differ.
build_rsync_args() {
    RSYNC_ARGS=(
        --recursive --links --times --compress
        --omit-dir-times
        --no-perms --no-owner --no-group
        --checksum
        --safe-links
        --human-readable
        --itemize-changes
        --delete-delay
        "--max-delete=${MAX_DELETE}"
        -e "ssh ${SSH_OPTS[*]}"
    )

    local pattern
    for pattern in "${EXCLUDES[@]}"; do RSYNC_ARGS+=("--exclude=${pattern}"); done
    for pattern in "${PROTECT_REMOTE[@]}"; do RSYNC_ARGS+=("--filter=protect ${pattern}"); done
}

# The itemised output, sorted into the five things a reader actually wants to know.
classify() {
    local output="$1"

    NEW_FILES=(); UPD_FILES=(); NEW_DIRS=(); DEL_FILES=(); DEL_DIRS=(); TOUCH_FILES=()

    local line name
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue

        case "$line" in
            '*deleting'*)
                name="${line#\*deleting}"
                name="${name#"${name%%[![:space:]]*}"}"
                if [[ "$name" == */ ]]; then DEL_DIRS+=("$name"); else DEL_FILES+=("$name"); fi
                ;;
            [\<\>]f+++++++++*) NEW_FILES+=("${line:12}") ;;
            [\<\>]f*)          UPD_FILES+=("${line:12}") ;;
            cd+++++++++*)      NEW_DIRS+=("${line:12}") ;;
            # No transfer, but the timestamp is about to be corrected. Not a content change, and worth
            # its own line rather than being dropped: this tree was uploaded by hand once, so nearly
            # every file carries a timestamp that never matched its source.
            .f*t*)             TOUCH_FILES+=("${line:12}") ;;
            *) : ;;
        esac
    done <<< "$output"
}

print_list() {
    local marker="$1" colour="$2"; shift 2
    local item

    for item in "$@"; do printf '  %s%s %s%s\n' "$colour" "$marker" "$item" "$C_RESET"; done
}

print_plan() {
    local -i changes=$(( ${#NEW_FILES[@]} + ${#UPD_FILES[@]} + ${#NEW_DIRS[@]} \
        + ${#DEL_FILES[@]} + ${#DEL_DIRS[@]} ))

    if (( ${#NEW_DIRS[@]} )); then
        printf '\nDirectories to create (%d):\n' "${#NEW_DIRS[@]}"
        print_list 'd+' "$C_GREEN" "${NEW_DIRS[@]}"
    fi

    if (( ${#NEW_FILES[@]} )); then
        printf '\nFiles to create (%d):\n' "${#NEW_FILES[@]}"
        print_list '+' "$C_GREEN" "${NEW_FILES[@]}"
    fi

    if (( ${#UPD_FILES[@]} )); then
        printf '\nFiles to update (%d):\n' "${#UPD_FILES[@]}"
        print_list '~' "$C_YELLOW" "${UPD_FILES[@]}"
    fi

    if (( ${#DEL_FILES[@]} )); then
        printf '\nFiles to delete (%d):\n' "${#DEL_FILES[@]}"
        print_list '-' "$C_RED" "${DEL_FILES[@]}"
    fi

    if (( ${#DEL_DIRS[@]} )); then
        printf '\nDirectories to delete (%d):\n' "${#DEL_DIRS[@]}"
        print_list '-d' "$C_RED" "${DEL_DIRS[@]}"
    fi

    if (( ${#TOUCH_FILES[@]} )); then
        printf '\nTimestamps to correct (%d): contents already identical, only the modification\n' \
            "${#TOUCH_FILES[@]}"
        printf '  time differs. A hand-uploaded tree never carried its source timestamps; this is the\n'
        printf '  one-off cleanup, after which a preview of an unchanged checkout prints nothing.\n'
    fi

    if (( changes == 0 && ${#TOUCH_FILES[@]} == 0 )); then
        printf '\nNothing to do: production src/ already matches this checkout.\n'
    fi

    # A migration file can travel with src/ but cannot run itself, and an unrun migration is how a
    # deploy that "worked" turns into 500s on the pages that touch the new tables.
    local item
    for item in "${NEW_FILES[@]}" "${UPD_FILES[@]}"; do
        if [[ "$item" == Migration/* ]]; then
            printf '\n%sThis release includes migration files.%s Run them yourself, deliberately:\n' \
                "$C_YELLOW$C_BOLD" "$C_RESET"
            printf '  ssh %s\n  cd %s && ./yii migrate:new    # inspect first\n' "$SSH_HOST" "$REMOTE_ROOT"
            break
        fi
    done

    printf '\n'

    return 0
}

dry_run() {
    build_rsync_args

    local status=0
    DRY_OUTPUT="$(rsync "${RSYNC_ARGS[@]}" --dry-run \
        "$LOCAL_SRC" "${SSH_HOST}:${REMOTE_SRC}/" 2>&1)" || status=$?

    if (( status == 25 )); then
        # The cap is enforced by rsync, so it stops the comparison too — which is the right way round:
        # a preview that quietly listed fewer deletions than a deploy would perform is the dangerous one.
        die "more than ${MAX_DELETE} deletion(s) pending, which is the cap." \
            'Nothing was sent, and the lists above are incomplete because the comparison stopped.' \
            'To see the whole picture, raise the cap for one run:' \
            "  KF_MAX_DELETE=100 ./deploy.sh"
    fi

    if (( status != 0 )); then
        printf '%s\n' "$DRY_OUTPUT" >&2
        die "the dry run itself failed (rsync exit ${status}); nothing was sent."
    fi

    classify "$DRY_OUTPUT"
}

# ---------------------------------------------------------------------------------- commands

header() {
    rule
    printf 'Knowledge Forge — Deployment %s\n' "$1"
    rule
    printf '\nEnvironment:   PRODUCTION\n'
    printf 'SSH host:      %s\n' "$SSH_HOST"
    printf 'Local source:  %s\n' "$LOCAL_SRC"
    printf 'Remote target: %s/\n' "$REMOTE_SRC"
    printf 'Mode:          %s\n\n' "$2"
}

cmd_preview() {
    header 'Preview' 'DRY RUN — no remote file is touched'

    check_local
    check_destination_string
    check_remote
    check_coupled_files

    step 'Comparing (checksums, both sides)'
    dry_run
    ok 'comparison complete'

    print_plan

    printf '%sNo production files were modified.%s\n\n' "$C_DIM" "$C_RESET"
    printf 'To deploy, after reading the list above:\n  ./deploy.sh deploy\n'
}

guard_deletions() {
    local -i total=$(( ${#DEL_FILES[@]} + ${#DEL_DIRS[@]} ))

    (( total == 0 )) && return 0

    if (( total > MAX_DELETE )); then
        die "${total} deletions proposed, cap is ${MAX_DELETE} — stopping for review." \
            'Read the list above. If every one of them is intended, raise the cap for a single run:' \
            "  KF_MAX_DELETE=${total} ./deploy.sh deploy"
    fi

    warn "${total} file(s)/directory(ies) will be deleted from production (listed above)."
    warn "Each one is moved into ${REMOTE_BACKUPS}/<timestamp>/ first, not erased."
}

guard_coupled() {
    (( ${#COUPLED_DIFFS[@]} == 0 )) && return 0

    [[ "${KF_ALLOW_SRC_ONLY:-0}" == '1' ]] && {
        warn 'KF_ALLOW_SRC_ONLY=1 — proceeding with a src/-only deploy despite the above.'

        return 0
    }

    die 'files outside src/ differ from production, so a src/-only deploy would be incomplete.' \
        'Listed above. Deploy those by your usual method first, then run this again.' \
        'If you have judged this release safe without them:' \
        '  KF_ALLOW_SRC_ONLY=1 ./deploy.sh deploy'
}

confirm() {
    [[ -t 0 ]] || die 'deploy needs an interactive terminal.' \
        'This is a manual deployment script on purpose; it will not run from cron, a pipe or CI.'

    printf '\n%sType %s to deploy to PRODUCTION, anything else to abort:%s ' \
        "$C_BOLD" "$CONFIRM_WORD" "$C_RESET"

    local reply=''
    read -r reply || true

    [[ "$reply" == "$CONFIRM_WORD" ]] || die 'not confirmed; nothing was sent.'
}

cmd_deploy() {
    header 'PRODUCTION' 'LIVE — files will be written'

    check_local
    check_destination_string
    check_remote
    check_coupled_files

    step 'Final preview before anything is written'
    dry_run
    print_plan

    local -i changes=$(( ${#NEW_FILES[@]} + ${#UPD_FILES[@]} + ${#NEW_DIRS[@]} \
        + ${#DEL_FILES[@]} + ${#DEL_DIRS[@]} ))

    if (( changes == 0 && ${#TOUCH_FILES[@]} == 0 )); then
        ok 'already up to date; nothing to deploy.'

        return 0
    fi

    guard_coupled
    guard_deletions

    local free_kb
    free_kb="$(fact free_kb)"
    if [[ -n "$free_kb" ]] && (( free_kb < 102400 )); then
        die "only ${free_kb} KB free on the server — refusing to write backups into a full disk."
    fi

    confirm

    local stamp backup_dir
    stamp="$(date +%Y%m%d-%H%M%S)"
    backup_dir="${REMOTE_BACKUPS}/${stamp}"

    step "Deploying (replaced and deleted files go to ${backup_dir}/)"

    build_rsync_args
    RSYNC_ARGS+=(--backup "--backup-dir=${backup_dir}")

    local status=0
    rsync "${RSYNC_ARGS[@]}" "$LOCAL_SRC" "${SSH_HOST}:${REMOTE_SRC}/" || status=$?

    printf '\n'

    if (( status == 0 )); then
        printf '%sDeployed.%s %s/ now matches this checkout'\''s src/.\n' \
            "$C_GREEN$C_BOLD" "$C_RESET" "$REMOTE_SRC"
        printf '\nVerify (read-only):\n'
        printf '  ssh %s '\''ls -l %s'\''\n' "$SSH_HOST" "$REMOTE_SRC"
        printf '  ./deploy.sh        # a clean preview should now report nothing to do\n'
        printf '\nBackup of what was replaced or removed:\n  %s/\n' "$backup_dir"
        printf '\nNot done, and not done automatically: composer, migrations, cache, service restarts.\n'

        return 0
    fi

    if (( status == 25 )); then
        die 'rsync hit --max-delete and stopped. Production was left as it was found.' \
            'Nothing was deleted. Review the deletion list, then raise the cap for one run.'
    fi

    die "rsync failed with exit status ${status}; the transfer is incomplete." \
        'Production may be partially updated. Re-run the preview to see what is still different,' \
        "and the backup directory ${backup_dir}/ holds whatever had already been replaced."
}

# ---------------------------------------------------------------------------------- entry point

PROJECT_ROOT="$(resolve_project_root)"
readonly PROJECT_ROOT
readonly LOCAL_SRC="${PROJECT_ROOT}/src/"

REMOTE_INFO=''
DRY_OUTPUT=''
declare -a NEW_FILES=() UPD_FILES=() NEW_DIRS=() DEL_FILES=() DEL_DIRS=() TOUCH_FILES=()
declare -a COUPLED_DIFFS=() COUPLED_COSMETIC=() RSYNC_ARGS=()

case "${1-}" in
    '')                 cmd_preview ;;
    preview|--preview)  cmd_preview ;;
    deploy)             cmd_deploy ;;
    -h|--help|help)     usage ;;
    *)
        printf 'unknown argument: %s\n\n' "$1" >&2
        usage >&2
        exit 2
        ;;
esac
