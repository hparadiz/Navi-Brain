#!/usr/bin/env bash
set -euo pipefail

experiment_root=/home/akujin/Sources/csbc-sparse-belief-ledger
llama_server=/home/akujin/Sources/ik_llama.cpp/build-kaiju-cpu/bin/llama-server
model_path=/mnt/kaiju/Models/qwen/qwen3.5-9b-heretic/Qwen3.5-9B-heretic.Q4_K_S.gguf
tape_path="$experiment_root/runs/endurance-100k-v1/E/events.jsonl"
sparse_summary="$experiment_root/runs/endurance-100k-v1/D/summary.json"
output_dir="$experiment_root/runs/endurance-100k-a-ctx131k-v1/A"
server_log="$experiment_root/runs/endurance-100k-a-ctx131k-v1-server.log"
large_server_pid=""

server_args=(
    -m "$model_path"
    --host 127.0.0.1
    --port 8081
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

restore_original_server() {
    local prior_status=$?
    trap - EXIT

    if [[ -n "$large_server_pid" ]] && kill -0 "$large_server_pid" 2>/dev/null; then
        kill "$large_server_pid"
        wait "$large_server_pid" 2>/dev/null || true
    fi

    if ! /usr/bin/curl --fail --silent http://127.0.0.1:8081/health >/dev/null; then
        /usr/bin/systemd-run \
            --unit=csbc-qwen-50k-post-replay \
            --collect \
            --property='Description=CSBC Qwen 50k server restored after 131k replay' \
            /usr/sbin/numactl --interleave=all "$llama_server" \
            -c 50000 "${server_args[@]}"
        wait_for_health || echo "warning: restored 50k server did not become healthy" >&2
    fi

    exit "$prior_status"
}

while systemctl is-active --quiet csbc-endurance-d-v1-clean.service; do
    sleep 30
done

if [[ ! -s "$sparse_summary" ]]; then
    echo "sparse D did not produce its summary; refusing to change the server" >&2
    exit 1
fi

if [[ -e "$output_dir" ]]; then
    echo "output already exists: $output_dir" >&2
    exit 1
fi

trap restore_original_server EXIT

systemctl stop csbc-qwen.service 2>/dev/null || true
systemctl stop csbc-qwen-50k-post-replay.service 2>/dev/null || true

for attempt in {1..60}; do
    if ! /usr/bin/curl --fail --silent http://127.0.0.1:8081/health >/dev/null; then
        break
    fi
    sleep 1
done

/usr/sbin/numactl --interleave=all "$llama_server" \
    -c 131072 "${server_args[@]}" >"$server_log" 2>&1 &
large_server_pid=$!

if ! wait_for_health "$large_server_pid"; then
    echo "131,072-context server failed to become healthy; see $server_log" >&2
    exit 1
fi

set +e
/usr/bin/python3 "$experiment_root/scripts/run_reconstruction.py" \
    --condition A \
    --seed 42 \
    --request-timeout-seconds 1200 \
    --tape "$tape_path" \
    --out "$output_dir"
run_status=$?
set -e

if [[ -s "$output_dir/ledger.jsonl" && -s "$output_dir/summary.json" ]]; then
    sha256sum "$output_dir/ledger.jsonl" "$output_dir/summary.json" \
        >"$output_dir/SHA256SUMS"
fi

exit "$run_status"
