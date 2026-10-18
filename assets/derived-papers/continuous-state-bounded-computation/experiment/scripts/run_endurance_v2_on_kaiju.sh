#!/usr/bin/env bash
set -euo pipefail

experiment_root=/home/akujin/Sources/csbc-sparse-belief-ledger
run_root="$experiment_root/runs/endurance-100k-v2"
llama_server=/home/akujin/Sources/ik_llama.cpp/build-kaiju-cpu/bin/llama-server
model_path=/mnt/kaiju/Models/qwen/qwen3.5-9b-heretic/Qwen3.5-9B-heretic.Q4_K_S.gguf
tape_path="$experiment_root/runs/endurance-100k-v1/E/events.jsonl"
server_pid=""

server_args=(
    -m "$model_path"
    --host 127.0.0.1
    --port 8081
    -c 131072
    -t 24
    -b 512
    -ub 128
    -ctk q8_0
    -ctv q4_0
    --flash-attn on
    --run-time-repack
    --mlock
    --no-warmup
    --jinja
    --merge-qkv
    --reasoning off
    --reasoning-budget 0
)

wait_for_health() {
    local expected_pid=${1:-}
    local attempt
    for attempt in {1..300}; do
        if /usr/bin/curl --fail --silent http://127.0.0.1:8081/health >/dev/null; then
            return 0
        fi
        if [[ -n "$expected_pid" ]] && ! kill -0 "$expected_pid" 2>/dev/null; then
            return 1
        fi
        sleep 1
    done
    return 1
}

stop_measurement_server() {
    if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid"
        wait "$server_pid" 2>/dev/null || true
    fi
    server_pid=""
}

start_measurement_server() {
    local condition=$1
    stop_measurement_server
    /usr/sbin/numactl --interleave=all "$llama_server" \
        "${server_args[@]}" >"$run_root/server-$condition.log" 2>&1 &
    server_pid=$!
    if ! wait_for_health "$server_pid"; then
        echo "131,072-context server failed during $condition startup" >&2
        return 1
    fi
}

restore_original_server() {
    local prior_status=$?
    trap - EXIT
    stop_measurement_server

    if ! /usr/bin/curl --fail --silent http://127.0.0.1:8081/health >/dev/null; then
        /usr/bin/systemd-run \
            --unit=csbc-qwen-50k-post-v2 \
            --collect \
            --property='Description=CSBC Qwen 50k server restored after endurance v2' \
            /usr/sbin/numactl --interleave=all "$llama_server" \
            -m "$model_path" \
            --host 127.0.0.1 \
            --port 8081 \
            -c 50000 \
            -t 24 \
            -b 512 \
            -ub 128 \
            -ctk q8_0 \
            -ctv q4_0 \
            --flash-attn on \
            --run-time-repack \
            --mlock \
            --no-warmup \
            --jinja \
            --merge-qkv \
            --reasoning off \
            --reasoning-budget 0
        wait_for_health || echo "warning: restored 50k server did not become healthy" >&2
    fi

    exit "$prior_status"
}

if [[ -e "$run_root" ]]; then
    echo "run directory already exists: $run_root" >&2
    exit 1
fi
mkdir -p "$run_root"
trap restore_original_server EXIT

systemctl stop csbc-qwen.service 2>/dev/null || true
systemctl stop csbc-qwen-50k-post-replay.service 2>/dev/null || true
systemctl stop csbc-qwen-50k-post-v2.service 2>/dev/null || true

for attempt in {1..60}; do
    if ! /usr/bin/curl --fail --silent http://127.0.0.1:8081/health >/dev/null; then
        break
    fi
    sleep 1
done

# Gate the entire long run on a successful full-history reconstruction first.
start_measurement_server A
/usr/bin/python3 "$experiment_root/scripts/run_reconstruction.py" \
    --condition A \
    --seed 42 \
    --request-timeout-seconds 1200 \
    --tape "$tape_path" \
    --out "$run_root/A"
sha256sum "$run_root/A/ledger.jsonl" "$run_root/A/summary.json" \
    >"$run_root/A/SHA256SUMS"

# Restart between conditions so no condition inherits another's context cache.
start_measurement_server B
/usr/bin/python3 "$experiment_root/scripts/run_reconstruction.py" \
    --condition B \
    --window 10 \
    --seed 42 \
    --request-timeout-seconds 180 \
    --tape "$tape_path" \
    --out "$run_root/B"
sha256sum "$run_root/B/ledger.jsonl" "$run_root/B/summary.json" \
    >"$run_root/B/SHA256SUMS"

start_measurement_server D
/usr/bin/python3 "$experiment_root/scripts/run_sparse_neural.py" \
    --condition D \
    --protocol compact \
    --seed 42 \
    --request-timeout-seconds 180 \
    --progress-every 5000 \
    --tape "$tape_path" \
    --out "$run_root/D"
sha256sum "$run_root/D/ledger.jsonl" "$run_root/D/summary.json" \
    >"$run_root/D/SHA256SUMS"

sha256sum "$tape_path" >"$run_root/SOURCE_TAPE_SHA256"
