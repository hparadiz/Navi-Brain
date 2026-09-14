#define _GNU_SOURCE
#define _POSIX_C_SOURCE 200809L

#include <ctype.h>
#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <inttypes.h>
#include <limits.h>
#include <poll.h>
#include <pthread.h>
#include <signal.h>
#include <sqlite3.h>
#include <stdarg.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/file.h>
#include <sys/ioctl.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <sys/types.h>
#include <sys/un.h>
#include <time.h>
#include <unistd.h>

#define MEMORY_MAGIC "NAVIMEM1"
#define PROTOCOL_ABI "TOKMEM/1"
#define MEMORY_HEADER_SIZE 16u
#define FORMATION_THRESHOLD 4u
#define ASSOCIATION_WINDOW 16u
#define DEFAULT_RANK_INTERVAL_MS 250u
#define MIN_RANK_INTERVAL_MS 10u
#define MAX_RANK_INTERVAL_MS 60000u
#define MAX_REQUEST_BYTES (64u * 1024u * 1024u)
#define MAX_RESPONSE_BYTES (64u * 1024u * 1024u)
#define MAX_MEMORY_BLOB_BYTES (64u * 1024u * 1024u)
#define MAX_EXPANDED_POSTING_NODES (4u * 1024u * 1024u)
#define MAX_CUE_LINK_SOURCES 256u
#define CUE_CONSTITUENT_WEIGHT UINT64_C(512)
#define CUE_ROOT_WEIGHT UINT64_C(1024)
#define DAEMON_WORKER_COUNT 8u
#define DAEMON_QUEUE_CAPACITY 64u
#define DAEMON_PENDING_CAPACITY 128u
#define DAEMON_PENDING_TOTAL (DAEMON_PENDING_CAPACITY + 1u)
#define DAEMON_REQUEST_BUDGET_BYTES (2u * MAX_REQUEST_BYTES)
#define DAEMON_RESPONSE_CAPACITY (DAEMON_QUEUE_CAPACITY + DAEMON_WORKER_COUNT)
#define DAEMON_RESPONSE_BUDGET_BYTES \
    (2u * MAX_RESPONSE_BYTES + DAEMON_RESPONSE_CAPACITY * 4096u)
#define DAEMON_SMALL_RESPONSE_RESERVATION 4096u
#define DAEMON_POLL_INTERVAL_MS 250
#define DAEMON_CLIENT_TIMEOUT_MS 5000u
#define DAEMON_FAST_LANE_TIMEOUT_MS 250u
#define NO_TOKEN UINT64_MAX
#define MAX_COUNTER UINT64_MAX
#define LINK_PERSIST_DIRTY UINT32_C(0x80000000)
#define LINK_ORDER_DIRTY UINT32_C(0x40000000)
#define LINK_EVIDENCE_MASK UINT32_C(0x3fffffff)
#define PUBLISH_CLEAN_FAILURE (-1)
#define PUBLISH_AMBIGUOUS_FAILURE (-2)
#ifndef RENAME_NOREPLACE
#define RENAME_NOREPLACE 1
#endif
#ifndef RENAME_EXCHANGE
#define RENAME_EXCHANGE 2
#endif

typedef struct TrieNode TrieNode;
typedef struct Memory Memory;

typedef struct {
    unsigned char *data;
    size_t size;
} Bytes;

typedef struct {
    unsigned char *items;
    size_t count;
    size_t capacity;
} Buffer;

typedef struct {
    uint64_t *items;
    size_t count;
    size_t capacity;
} TokenVector;

typedef struct {
    const unsigned char *data;
    size_t size;
    uint64_t folded_hash;
} CueSpan;

typedef struct {
    size_t token_index;
    size_t byte_offset;
    size_t size;
    uint64_t folded_hash;
} MemorySpan;

typedef struct {
    unsigned char byte;
    TrieNode *child;
} TrieEdge;

struct TrieNode {
    uint64_t token_id;
    TrieEdge *edges;
    size_t edge_count;
    size_t edge_capacity;
};

typedef struct {
    uint64_t target;
    uint32_t evidence_and_dirty;
    uint32_t reinforcement;
} Link;

typedef struct {
    Memory *memory;
    uint64_t occurrences;
} Posting;

typedef struct {
    unsigned char *segment;
    size_t segment_size;
    uint64_t write_count;
    uint64_t read_count;
    uint64_t usage_count;
    uint64_t order_write_count;
    uint64_t order_read_count;
    uint64_t order_usage_count;
    uint64_t left;
    uint64_t right;
    bool has_children;
    bool counters_dirty;
    bool order_dirty;
    size_t order_position;
    Link *links;
    size_t link_count;
    size_t link_capacity;
    size_t link_cursor;
    uint32_t *link_index;
    size_t link_index_capacity;
    Posting *postings;
    size_t posting_count;
    size_t posting_capacity;
    size_t posting_cursor;
} Token;

typedef enum {
    SOURCE_EVENT_UNKNOWN = 0,
    SOURCE_EVENT_EXECUTIVE,
    SOURCE_EVENT_SENSE
} SourceEventKind;

typedef struct {
    int64_t id;
    Bytes created_at;
    Bytes updated_at;
    Bytes tier;
    double confidence;
    Bytes status;
    bool has_source_event_id;
    int64_t source_event_id;
    SourceEventKind source_event_kind;
    bool has_source_memory_id;
    int64_t source_memory_id;
    bool has_supersedes_id;
    int64_t supersedes_id;
    bool has_expires_at;
    Bytes expires_at;
} Metadata;

struct Memory {
    Metadata metadata;
    char *key;
    char *tier_name;
    uint64_t *tokens;
    size_t token_count;
    size_t content_byte_count;
    uint64_t *metadata_tokens;
    size_t metadata_token_count;
    unsigned char content_digest[32];
    unsigned char content_bytes_digest[32];
    unsigned char metadata_digest[32];
    uint64_t access_count;
    uint64_t query_score;
    uint64_t query_match_score;
    uint64_t query_span_hits;
    uint64_t query_span_bytes;
    uint32_t query_generation;
    size_t insertion_index;
    bool access_dirty;
    bool visible;
};

typedef struct {
    char *tier;
    char *status;
    Memory **id_order;
    Memory **updated_order;
    size_t count;
    size_t capacity;
} MemoryFilterIndex;

typedef struct {
    uint64_t hash;
    uint64_t token_id;
    bool used;
} SegmentSlot;

typedef struct {
    SegmentSlot *slots;
    size_t capacity;
    size_t count;
} SegmentMap;

typedef struct {
    uint64_t left;
    uint64_t right;
    uint64_t observations;
    bool used;
    bool dirty;
} PairSlot;

typedef struct {
    PairSlot *slots;
    size_t capacity;
    size_t count;
} PairMap;

typedef struct { uint64_t left, right; } PairKey;
typedef struct { uint64_t source, target; } EdgeKey;

typedef struct {
    char *store_path;
    char *memory_dir;
    char *catalog_path;
    sqlite3 *db;
    int lock_fd;
    Token *tokens;
    size_t token_count;
    size_t token_capacity;
    uint64_t *token_order;
    size_t token_order_count;
    size_t token_order_capacity;
    size_t token_order_cursor;
    uint64_t *dirty_tokens;
    size_t dirty_head;
    size_t dirty_count;
    size_t dirty_capacity;
    uint64_t *order_tokens;
    size_t order_token_head;
    size_t order_token_count;
    size_t order_token_capacity;
    PairKey *dirty_pairs;
    size_t dirty_pair_head;
    size_t dirty_pair_count;
    size_t dirty_pair_capacity;
    EdgeKey *dirty_links;
    size_t dirty_link_head;
    size_t dirty_link_count;
    size_t dirty_link_capacity;
    EdgeKey *order_links;
    size_t order_link_head;
    size_t order_link_count;
    size_t order_link_capacity;
    Memory **dirty_memories;
    size_t dirty_memory_head;
    size_t dirty_memory_count;
    size_t dirty_memory_capacity;
    TrieNode *trie;
    SegmentMap segments;
    PairMap pairs;
    size_t association_count;
    Memory **memory_order;
    Memory **memory_id_order;
    Memory **memory_updated_order;
    Memory **memory_source_memory_order;
    Memory **memory_source_event_order;
    size_t memory_source_memory_count;
    size_t memory_source_event_count;
    MemoryFilterIndex *memory_filter_indexes;
    size_t memory_filter_index_count;
    size_t memory_filter_index_capacity;
    Memory **query_touched;
    size_t query_touched_capacity;
    Memory **query_results;
    size_t query_result_capacity;
    uint32_t query_generation;
    uint32_t *query_token_marks;
    size_t query_token_mark_capacity;
    uint32_t query_token_generation;
    size_t memory_count;
    size_t memory_capacity;
    size_t memory_cursor;
    pthread_mutex_t mutex;
    pthread_cond_t condition;
    pthread_t rank_thread;
    atomic_bool stop_ranker;
    bool mutex_ready;
    bool condition_ready;
    bool ranker_started;
    bool poisoned;
    bool ordering_ready;
    bool order_enqueue_enabled;
    unsigned rank_interval_ms;
} Engine;

typedef struct {
    int fd;
    size_t request_bytes;
    unsigned char *request;
    size_t request_size;
} DaemonClient;

typedef struct {
    uint64_t id;
    int fd;
    unsigned char header[64];
    size_t header_size;
    size_t header_sent;
    unsigned char *payload;
    size_t payload_size;
    size_t payload_sent;
    size_t reservation;
    uint64_t deadline_ms;
    bool close_response;
} DaemonResponse;

typedef struct {
    Engine *engine;
    DaemonClient clients[DAEMON_QUEUE_CAPACITY];
    size_t client_head;
    size_t client_count;
    size_t request_bytes;
    pthread_mutex_t queue_mutex;
    pthread_cond_t queue_ready;
    pthread_rwlock_t admission;
    pthread_t workers[DAEMON_WORKER_COUNT];
    size_t worker_count;
    DaemonResponse responses[DAEMON_RESPONSE_CAPACITY];
    size_t response_count;
    size_t response_bytes;
    uint64_t next_response_id;
    pthread_mutex_t response_mutex;
    pthread_t writer;
    bool writer_started;
    bool response_accepting_done;
    int writer_wake_read;
    int writer_wake_write;
    bool accepting_done;
    atomic_bool closing;
    atomic_bool discard_responses;
    atomic_bool writer_failed;
    atomic_bool close_flush_failed;
    atomic_int close_client;
} DaemonRuntime;

typedef struct {
    int fd;
    uint64_t deadline_ms;
    unsigned char *request;
    size_t request_size;
    size_t request_capacity;
    size_t expected_size;
    size_t request_bytes;
    bool header_parsed;
    bool request_reserved;
    bool complete;
    bool close_command;
} PendingClient;

typedef struct {
    const unsigned char *data;
    size_t size;
    size_t offset;
} RequestReader;

static volatile sig_atomic_t daemon_stop;
static void enqueue_order_token(Engine *engine, uint64_t token_id);

static void usage(FILE *out)
{
    fprintf(out,
            "usage:\n"
            "  tokmem init STORE\n"
            "  tokmem import STORE SOURCE.sqlite [--daemon]\n"
            "  tokmem catchup STORE SOURCE.sqlite\n"
            "  tokmem export STORE DEST.sqlite\n"
            "  tokmem daemon STORE [SOCKET]\n"
            "  tokmem verify STORE\n"
            "  tokmem tokens STORE [LIMIT]\n"
            "  tokmem client SOCKET capabilities|stats|flush|close\n"
            "  tokmem client SOCKET get TIER ID\n"
            "  tokmem client SOCKET fetch ID\n"
            "  tokmem client SOCKET list TIER|- STATUS|- id|updated_at ASC|DESC LIMIT\n"
            "  tokmem client SOCKET query INPUT [LIMIT]\n"
            "  tokmem client SOCKET recall-records INPUT [LIMIT]\n"
            "  tokmem client SOCKET activate INPUT TOKEN_BUDGET\n"
            "  tokmem client SOCKET add TIER ID INPUT\n"
            "  tokmem client SOCKET create OP_KEY METADATA CONTENT\n"
            "  tokmem client SOCKET update OP_KEY METADATA CONTENT\n"
            "  tokmem client SOCKET replace OP_KEY NEW_METADATA CONTENT OLD_METADATA\n");
}

static void *xmalloc(size_t size)
{
    void *p = malloc(size == 0 ? 1 : size);
    if (p == NULL) {
        fputs("out of memory\n", stderr);
        exit(EXIT_FAILURE);
    }
    return p;
}

static void *xcalloc(size_t count, size_t size)
{
    void *p = calloc(count == 0 ? 1 : count, size == 0 ? 1 : size);
    if (p == NULL) {
        fputs("out of memory\n", stderr);
        exit(EXIT_FAILURE);
    }
    return p;
}

static void *xrealloc(void *old, size_t size)
{
    void *p = realloc(old, size == 0 ? 1 : size);
    if (p == NULL) {
        fputs("out of memory\n", stderr);
        exit(EXIT_FAILURE);
    }
    return p;
}

static char *xstrdup(const char *text)
{
    size_t size = strlen(text) + 1;
    char *copy = xmalloc(size);
    memcpy(copy, text, size);
    return copy;
}

static char *path_join(const char *left, const char *right)
{
    size_t left_size = strlen(left);
    size_t right_size = strlen(right);
    bool slash = left_size != 0 && left[left_size - 1] != '/';
    char *path;

    if (left_size > SIZE_MAX - right_size - (slash ? 2u : 1u)) {
        fputs("path too long\n", stderr);
        exit(EXIT_FAILURE);
    }
    path = xmalloc(left_size + right_size + (slash ? 2u : 1u));
    memcpy(path, left, left_size);
    if (slash) {
        path[left_size++] = '/';
    }
    memcpy(path + left_size, right, right_size + 1);
    return path;
}

static char *path_suffix(const char *path, const char *suffix)
{
    size_t path_size = strlen(path);
    size_t suffix_size = strlen(suffix);
    char *result;
    if (path_size > SIZE_MAX - suffix_size - 1) {
        fputs("path too long\n", stderr);
        exit(EXIT_FAILURE);
    }
    result = xmalloc(path_size + suffix_size + 1);
    memcpy(result, path, path_size);
    memcpy(result + path_size, suffix, suffix_size + 1);
    return result;
}

static int rename_noreplace(const char *source, const char *destination)
{
#ifdef __linux__
    if (renameat2(AT_FDCWD, source, AT_FDCWD, destination,
                  RENAME_NOREPLACE) == 0) {
        return 0;
    }
    if (errno != ENOSYS && errno != EINVAL) return -1;
#endif
    {
        struct stat st;
        if (lstat(source, &st) != 0) return -1;
        if (!S_ISREG(st.st_mode)) {
            errno = ENOTSUP;
            return -1;
        }
        if (link(source, destination) != 0) return -1;
        if (unlink(source) != 0) {
            int saved = errno;
            unlink(destination);
            errno = saved;
            return -1;
        }
    }
    return 0;
}

static int rename_exchange(const char *left, const char *right)
{
#ifdef __linux__
    return renameat2(AT_FDCWD, left, AT_FDCWD, right, RENAME_EXCHANGE);
#else
    (void)left;
    (void)right;
    errno = ENOTSUP;
    return -1;
#endif
}

static void buffer_reserve(Buffer *buffer, size_t extra)
{
    size_t needed;
    if (extra > SIZE_MAX - buffer->count) {
        fputs("buffer too large\n", stderr);
        exit(EXIT_FAILURE);
    }
    needed = buffer->count + extra;
    if (needed <= buffer->capacity) {
        return;
    }
    buffer->capacity = buffer->capacity == 0 ? 256 : buffer->capacity;
    while (buffer->capacity < needed) {
        if (buffer->capacity > SIZE_MAX / 2) {
            buffer->capacity = needed;
            break;
        }
        buffer->capacity *= 2;
    }
    buffer->items = xrealloc(buffer->items, buffer->capacity);
}

static void buffer_append(Buffer *buffer, const void *data, size_t size)
{
    buffer_reserve(buffer, size);
    memcpy(buffer->items + buffer->count, data, size);
    buffer->count += size;
}

static void buffer_append_text(Buffer *buffer, const char *text)
{
    buffer_append(buffer, text, strlen(text));
}

static int buffer_printf(Buffer *buffer, const char *format, ...)
{
    va_list args;
    va_list copy;
    int needed;

    va_start(args, format);
    va_copy(copy, args);
    needed = vsnprintf(NULL, 0, format, copy);
    va_end(copy);
    if (needed < 0) {
        va_end(args);
        return -1;
    }
    buffer_reserve(buffer, (size_t)needed + 1);
    vsnprintf((char *)buffer->items + buffer->count,
              buffer->capacity - buffer->count, format, args);
    va_end(args);
    buffer->count += (size_t)needed;
    return 0;
}

static void token_vector_push(TokenVector *vector, uint64_t token_id)
{
    if (vector->count == vector->capacity) {
        vector->capacity = vector->capacity == 0 ? 64 : vector->capacity * 2;
        vector->items = xrealloc(vector->items,
                                 vector->capacity * sizeof(*vector->items));
    }
    vector->items[vector->count++] = token_id;
}

static int write_all(int fd, const void *data, size_t size)
{
    const unsigned char *bytes = data;
    while (size != 0) {
        ssize_t written = write(fd, bytes, size);
        if (written < 0) {
            if (errno == EINTR && !daemon_stop) {
                continue;
            }
            return -1;
        }
        bytes += (size_t)written;
        size -= (size_t)written;
    }
    return 0;
}

static int read_exact(int fd, void *data, size_t size)
{
    unsigned char *bytes = data;
    while (size != 0) {
        ssize_t got = read(fd, bytes, size);
        if (got < 0) {
            if (errno == EINTR && !daemon_stop) {
                continue;
            }
            return -1;
        }
        if (got == 0) {
            return -1;
        }
        bytes += (size_t)got;
        size -= (size_t)got;
    }
    return 0;
}

static int read_all_fd_capped(int fd, size_t limit,
                              unsigned char **result, size_t *result_size)
{
    Buffer buffer = {0};
    for (;;) {
        unsigned char chunk[16384];
        ssize_t got = read(fd, chunk, sizeof(chunk));
        if (got < 0) {
            if (errno == EINTR && !daemon_stop) continue;
            free(buffer.items);
            return -1;
        }
        if (got == 0) break;
        if ((size_t)got > limit - buffer.count) {
            free(buffer.items);
            errno = EFBIG;
            return -1;
        }
        buffer_append(&buffer, chunk, (size_t)got);
    }
    *result = buffer.items;
    *result_size = buffer.count;
    return 0;
}

static int read_input_capped(const char *path, size_t limit,
                             unsigned char **result, size_t *result_size)
{
    int fd;
    int rc;
    if (strcmp(path, "-") == 0)
        return read_all_fd_capped(STDIN_FILENO, limit, result, result_size);
    fd = open(path, O_RDONLY | O_CLOEXEC);
    if (fd < 0) return -1;
    rc = read_all_fd_capped(fd, limit, result, result_size);
    if (close(fd) != 0 && rc == 0) rc = -1;
    return rc;
}

static uint64_t saturating_increment(uint64_t value)
{
    return value < MAX_COUNTER ? value + 1 : value;
}

static uint64_t hash_bytes(const unsigned char *bytes, size_t size)
{
    uint64_t hash = UINT64_C(1469598103934665603);
    for (size_t i = 0; i < size; ++i) {
        hash ^= bytes[i];
        hash *= UINT64_C(1099511628211);
    }
    hash ^= hash >> 33;
    hash *= UINT64_C(0xff51afd7ed558ccd);
    hash ^= hash >> 33;
    return hash;
}

static unsigned char fold_ascii(unsigned char byte)
{
    return byte >= 'A' && byte <= 'Z' ?
           (unsigned char)(byte + ('a' - 'A')) : byte;
}

static uint64_t hash_folded_bytes(const unsigned char *bytes, size_t size)
{
    uint64_t hash = UINT64_C(1469598103934665603);
    for (size_t i = 0; i < size; ++i) {
        hash ^= fold_ascii(bytes[i]);
        hash *= UINT64_C(1099511628211);
    }
    hash ^= hash >> 33;
    hash *= UINT64_C(0xff51afd7ed558ccd);
    hash ^= hash >> 33;
    return hash;
}

static bool cue_span_byte(unsigned char byte)
{
    return (byte >= 'a' && byte <= 'z') ||
           (byte >= 'A' && byte <= 'Z') ||
           (byte >= '0' && byte <= '9') || byte >= 0x80;
}

static bool folded_bytes_equal(const unsigned char *left,
                               const unsigned char *right, size_t size)
{
    for (size_t i = 0; i < size; ++i) {
        if (fold_ascii(left[i]) != fold_ascii(right[i])) return false;
    }
    return true;
}

static size_t collect_cue_spans(const unsigned char *cue, size_t cue_size,
                                CueSpan spans[MAX_CUE_LINK_SOURCES])
{
    size_t count = 0;
    size_t offset = 0;
    while (offset < cue_size && count < MAX_CUE_LINK_SOURCES) {
        size_t start;
        size_t size;
        uint64_t hash;
        bool duplicate = false;
        while (offset < cue_size && !cue_span_byte(cue[offset])) ++offset;
        start = offset;
        while (offset < cue_size && cue_span_byte(cue[offset])) ++offset;
        size = offset - start;
        if (size == 0) continue;
        hash = hash_folded_bytes(cue + start, size);
        for (size_t i = 0; i < count; ++i) {
            if (spans[i].size == size && spans[i].folded_hash == hash &&
                folded_bytes_equal(spans[i].data, cue + start, size)) {
                duplicate = true;
                break;
            }
        }
        if (!duplicate)
            spans[count++] = (CueSpan){cue + start, size, hash};
    }
    return count;
}

static uint64_t hash_pair(uint64_t left, uint64_t right)
{
    uint64_t x = left + UINT64_C(0x9e3779b97f4a7c15);
    uint64_t y = right + UINT64_C(0x517cc1b727220a95);
    x ^= x >> 30;
    x *= UINT64_C(0xbf58476d1ce4e5b9);
    x ^= y + (x << 6) + (x >> 2);
    x ^= x >> 27;
    x *= UINT64_C(0x94d049bb133111eb);
    return x ^ (x >> 31);
}

static TrieNode *trie_node_new(void)
{
    TrieNode *node = xcalloc(1, sizeof(*node));
    node->token_id = NO_TOKEN;
    return node;
}

static void trie_free(TrieNode *node)
{
    if (node == NULL) {
        return;
    }
    for (size_t i = 0; i < node->edge_count; ++i) {
        trie_free(node->edges[i].child);
    }
    free(node->edges);
    free(node);
}

static TrieNode *trie_child(TrieNode *node, unsigned char byte, bool create)
{
    for (size_t i = 0; i < node->edge_count; ++i) {
        if (node->edges[i].byte == byte) {
            return node->edges[i].child;
        }
    }
    if (!create) {
        return NULL;
    }
    if (node->edge_count == node->edge_capacity) {
        node->edge_capacity = node->edge_capacity == 0 ? 4 : node->edge_capacity * 2;
        node->edges = xrealloc(node->edges,
                               node->edge_capacity * sizeof(*node->edges));
    }
    node->edges[node->edge_count].byte = byte;
    node->edges[node->edge_count].child = trie_node_new();
    return node->edges[node->edge_count++].child;
}

static int trie_insert(TrieNode *root, const unsigned char *bytes, size_t size,
                       uint64_t token_id)
{
    TrieNode *node = root;
    for (size_t i = 0; i < size; ++i) {
        node = trie_child(node, bytes[i], true);
    }
    if (node->token_id != NO_TOKEN && node->token_id != token_id) {
        return -1;
    }
    node->token_id = token_id;
    return 0;
}

static void segment_map_rehash(SegmentMap *map, size_t capacity)
{
    SegmentSlot *old = map->slots;
    size_t old_capacity = map->capacity;
    map->slots = xcalloc(capacity, sizeof(*map->slots));
    map->capacity = capacity;
    map->count = 0;
    for (size_t i = 0; i < old_capacity; ++i) {
        size_t index;
        if (!old[i].used) {
            continue;
        }
        index = old[i].hash & (capacity - 1);
        while (map->slots[index].used) {
            index = (index + 1) & (capacity - 1);
        }
        map->slots[index] = old[i];
        ++map->count;
    }
    free(old);
}

static void segment_map_insert(SegmentMap *map, uint64_t hash, uint64_t token_id)
{
    size_t index;
    if (map->capacity == 0) {
        segment_map_rehash(map, 512);
    } else if ((map->count + 1) * 10 >= map->capacity * 7) {
        segment_map_rehash(map, map->capacity * 2);
    }
    index = hash & (map->capacity - 1);
    while (map->slots[index].used) {
        index = (index + 1) & (map->capacity - 1);
    }
    map->slots[index].used = true;
    map->slots[index].hash = hash;
    map->slots[index].token_id = token_id;
    ++map->count;
}

static uint64_t segment_map_find(const Engine *engine, const unsigned char *segment,
                                 size_t size, uint64_t hash)
{
    size_t index;
    if (engine->segments.capacity == 0) {
        return NO_TOKEN;
    }
    index = hash & (engine->segments.capacity - 1);
    while (engine->segments.slots[index].used) {
        const SegmentSlot *slot = &engine->segments.slots[index];
        const Token *token = &engine->tokens[slot->token_id];
        if (slot->hash == hash && token->segment_size == size &&
            memcmp(token->segment, segment, size) == 0) {
            return slot->token_id;
        }
        index = (index + 1) & (engine->segments.capacity - 1);
    }
    return NO_TOKEN;
}

static void pair_map_rehash(PairMap *map, size_t capacity)
{
    PairSlot *old = map->slots;
    size_t old_capacity = map->capacity;
    map->slots = xcalloc(capacity, sizeof(*map->slots));
    map->capacity = capacity;
    map->count = 0;
    for (size_t i = 0; i < old_capacity; ++i) {
        size_t index;
        if (!old[i].used) {
            continue;
        }
        index = hash_pair(old[i].left, old[i].right) & (capacity - 1);
        while (map->slots[index].used) {
            index = (index + 1) & (capacity - 1);
        }
        map->slots[index] = old[i];
        ++map->count;
    }
    free(old);
}

static void dirty_pair_push(Engine *engine, PairSlot *pair)
{
    if (pair->dirty) return;
    if (engine->dirty_pair_count == engine->dirty_pair_capacity &&
        engine->dirty_pair_head != 0) {
        size_t live = engine->dirty_pair_count - engine->dirty_pair_head;
        memmove(engine->dirty_pairs,
                engine->dirty_pairs + engine->dirty_pair_head,
                live * sizeof(*engine->dirty_pairs));
        engine->dirty_pair_head = 0;
        engine->dirty_pair_count = live;
    }
    if (engine->dirty_pair_count == engine->dirty_pair_capacity) {
        engine->dirty_pair_capacity = engine->dirty_pair_capacity == 0 ? 256 :
                                      engine->dirty_pair_capacity * 2;
        engine->dirty_pairs = xrealloc(engine->dirty_pairs,
            engine->dirty_pair_capacity * sizeof(*engine->dirty_pairs));
    }
    engine->dirty_pairs[engine->dirty_pair_count++] =
        (PairKey){pair->left, pair->right};
    pair->dirty = true;
}

static PairSlot *pair_map_set(Engine *engine, uint64_t left, uint64_t right,
                              uint64_t observations, bool dirty)
{
    PairMap *map = &engine->pairs;
    size_t index;
    if (map->capacity == 0) {
        pair_map_rehash(map, 1024);
    } else if ((map->count + 1) * 10 >= map->capacity * 7) {
        pair_map_rehash(map, map->capacity * 2);
    }
    index = hash_pair(left, right) & (map->capacity - 1);
    while (map->slots[index].used) {
        if (map->slots[index].left == left && map->slots[index].right == right) {
            map->slots[index].observations = observations;
            if (dirty) dirty_pair_push(engine, &map->slots[index]);
            return &map->slots[index];
        }
        index = (index + 1) & (map->capacity - 1);
    }
    map->slots[index].used = true;
    map->slots[index].left = left;
    map->slots[index].right = right;
    map->slots[index].observations = observations;
    ++map->count;
    if (dirty) dirty_pair_push(engine, &map->slots[index]);
    return &map->slots[index];
}

static PairSlot *pair_map_find(PairMap *map, uint64_t left, uint64_t right)
{
    size_t index;
    if (map->capacity == 0) return NULL;
    index = hash_pair(left, right) & (map->capacity - 1);
    while (map->slots[index].used) {
        if (map->slots[index].left == left && map->slots[index].right == right)
            return &map->slots[index];
        index = (index + 1) & (map->capacity - 1);
    }
    return NULL;
}

static uint64_t pair_delta_observe(PairMap *deltas, uint64_t left, uint64_t right)
{
    PairSlot *slot;
    size_t index;
    if (deltas->capacity == 0) {
        pair_map_rehash(deltas, 64);
    } else if ((deltas->count + 1) * 10 >= deltas->capacity * 7) {
        pair_map_rehash(deltas, deltas->capacity * 2);
    }
    index = hash_pair(left, right) & (deltas->capacity - 1);
    while (deltas->slots[index].used) {
        if (deltas->slots[index].left == left && deltas->slots[index].right == right) {
            slot = &deltas->slots[index];
            slot->observations = saturating_increment(slot->observations);
            return slot->observations;
        }
        index = (index + 1) & (deltas->capacity - 1);
    }
    slot = &deltas->slots[index];
    slot->used = true;
    slot->left = left;
    slot->right = right;
    slot->observations = 1;
    ++deltas->count;
    return 1;
}

static uint64_t saturating_add_u64(uint64_t left, uint64_t right)
{
    return UINT64_MAX - left < right ? UINT64_MAX : left + right;
}

static void apply_pair_deltas(Engine *engine, PairMap *deltas)
{
    for (size_t i = 0; i < deltas->capacity; ++i) {
        PairSlot *delta = &deltas->slots[i];
        PairSlot *pair;
        if (!delta->used) continue;
        pair = pair_map_find(&engine->pairs, delta->left, delta->right);
        if (pair == NULL) {
            pair = pair_map_set(engine, delta->left, delta->right, 0, false);
        }
        pair->observations = saturating_add_u64(pair->observations,
                                                delta->observations);
        dirty_pair_push(engine, pair);
    }
}

static size_t link_index_hash(uint64_t target)
{
    target ^= target >> 33;
    target *= UINT64_C(0xff51afd7ed558ccd);
    target ^= target >> 33;
    return (size_t)target;
}

static int rebuild_link_index(Token *token, size_t capacity)
{
    uint32_t *index;
    if (capacity == 0 || (capacity & (capacity - 1)) != 0 ||
        token->link_count > UINT32_MAX || token->link_count >= capacity) {
        return -1;
    }
    index = xcalloc(capacity, sizeof(*index));
    for (size_t i = 0; i < token->link_count; ++i) {
        size_t slot = link_index_hash(token->links[i].target) & (capacity - 1);
        while (index[slot] != 0) {
            if (token->links[index[slot] - 1].target == token->links[i].target) {
                free(index);
                return -1;
            }
            slot = (slot + 1) & (capacity - 1);
        }
        index[slot] = (uint32_t)(i + 1);
    }
    free(token->link_index);
    token->link_index = index;
    token->link_index_capacity = capacity;
    return 0;
}

static uint32_t *link_index_slot(Token *token, uint64_t target, bool create)
{
    size_t slot;
    if (token->link_index_capacity == 0) {
        if (!create) return NULL;
        if (rebuild_link_index(token, 8) != 0) return NULL;
    } else if (create && (token->link_count + 1) * 10 >=
                         token->link_index_capacity * 7) {
        if (token->link_index_capacity > SIZE_MAX / 2 ||
            rebuild_link_index(token, token->link_index_capacity * 2) != 0) {
            return NULL;
        }
    }
    slot = link_index_hash(target) & (token->link_index_capacity - 1);
    while (token->link_index[slot] != 0) {
        size_t position = token->link_index[slot] - 1;
        if (position < token->link_count && token->links[position].target == target)
            return &token->link_index[slot];
        slot = (slot + 1) & (token->link_index_capacity - 1);
    }
    return create ? &token->link_index[slot] : NULL;
}

typedef struct {
    uint32_t state[8];
    uint64_t bit_count;
    unsigned char block[64];
    size_t block_size;
} Sha256;

static uint32_t rotate_right(uint32_t value, unsigned count)
{
    return (value >> count) | (value << (32u - count));
}

static void sha256_transform(Sha256 *sha, const unsigned char block[64])
{
    static const uint32_t constants[64] = {
        0x428a2f98u, 0x71374491u, 0xb5c0fbcfu, 0xe9b5dba5u,
        0x3956c25bu, 0x59f111f1u, 0x923f82a4u, 0xab1c5ed5u,
        0xd807aa98u, 0x12835b01u, 0x243185beu, 0x550c7dc3u,
        0x72be5d74u, 0x80deb1feu, 0x9bdc06a7u, 0xc19bf174u,
        0xe49b69c1u, 0xefbe4786u, 0x0fc19dc6u, 0x240ca1ccu,
        0x2de92c6fu, 0x4a7484aau, 0x5cb0a9dcu, 0x76f988dau,
        0x983e5152u, 0xa831c66du, 0xb00327c8u, 0xbf597fc7u,
        0xc6e00bf3u, 0xd5a79147u, 0x06ca6351u, 0x14292967u,
        0x27b70a85u, 0x2e1b2138u, 0x4d2c6dfcu, 0x53380d13u,
        0x650a7354u, 0x766a0abbu, 0x81c2c92eu, 0x92722c85u,
        0xa2bfe8a1u, 0xa81a664bu, 0xc24b8b70u, 0xc76c51a3u,
        0xd192e819u, 0xd6990624u, 0xf40e3585u, 0x106aa070u,
        0x19a4c116u, 0x1e376c08u, 0x2748774cu, 0x34b0bcb5u,
        0x391c0cb3u, 0x4ed8aa4au, 0x5b9cca4fu, 0x682e6ff3u,
        0x748f82eeu, 0x78a5636fu, 0x84c87814u, 0x8cc70208u,
        0x90befffau, 0xa4506cebu, 0xbef9a3f7u, 0xc67178f2u
    };
    uint32_t words[64];
    uint32_t a, b, c, d, e, f, g, h;

    for (size_t i = 0; i < 16; ++i) {
        words[i] = ((uint32_t)block[i * 4] << 24) |
                   ((uint32_t)block[i * 4 + 1] << 16) |
                   ((uint32_t)block[i * 4 + 2] << 8) |
                   block[i * 4 + 3];
    }
    for (size_t i = 16; i < 64; ++i) {
        uint32_t s0 = rotate_right(words[i - 15], 7) ^
                      rotate_right(words[i - 15], 18) ^ (words[i - 15] >> 3);
        uint32_t s1 = rotate_right(words[i - 2], 17) ^
                      rotate_right(words[i - 2], 19) ^ (words[i - 2] >> 10);
        words[i] = words[i - 16] + s0 + words[i - 7] + s1;
    }
    a = sha->state[0]; b = sha->state[1]; c = sha->state[2]; d = sha->state[3];
    e = sha->state[4]; f = sha->state[5]; g = sha->state[6]; h = sha->state[7];
    for (size_t i = 0; i < 64; ++i) {
        uint32_t s1 = rotate_right(e, 6) ^ rotate_right(e, 11) ^ rotate_right(e, 25);
        uint32_t choose = (e & f) ^ ((~e) & g);
        uint32_t t1 = h + s1 + choose + constants[i] + words[i];
        uint32_t s0 = rotate_right(a, 2) ^ rotate_right(a, 13) ^ rotate_right(a, 22);
        uint32_t majority = (a & b) ^ (a & c) ^ (b & c);
        uint32_t t2 = s0 + majority;
        h = g; g = f; f = e; e = d + t1; d = c; c = b; b = a; a = t1 + t2;
    }
    sha->state[0] += a; sha->state[1] += b; sha->state[2] += c; sha->state[3] += d;
    sha->state[4] += e; sha->state[5] += f; sha->state[6] += g; sha->state[7] += h;
}

static void sha256_init(Sha256 *sha)
{
    static const uint32_t initial[8] = {
        0x6a09e667u, 0xbb67ae85u, 0x3c6ef372u, 0xa54ff53au,
        0x510e527fu, 0x9b05688cu, 0x1f83d9abu, 0x5be0cd19u
    };
    memset(sha, 0, sizeof(*sha));
    memcpy(sha->state, initial, sizeof(initial));
}

static void sha256_update(Sha256 *sha, const unsigned char *data, size_t size)
{
    while (size != 0) {
        size_t room = sizeof(sha->block) - sha->block_size;
        size_t take = size < room ? size : room;
        memcpy(sha->block + sha->block_size, data, take);
        sha->block_size += take;
        sha->bit_count += (uint64_t)take * 8u;
        data += take;
        size -= take;
        if (sha->block_size == sizeof(sha->block)) {
            sha256_transform(sha, sha->block);
            sha->block_size = 0;
        }
    }
}

static void sha256_final(Sha256 *sha, unsigned char digest[32])
{
    uint64_t bit_count = sha->bit_count;
    sha->block[sha->block_size++] = 0x80;
    if (sha->block_size > 56) {
        memset(sha->block + sha->block_size, 0, 64 - sha->block_size);
        sha256_transform(sha, sha->block);
        sha->block_size = 0;
    }
    memset(sha->block + sha->block_size, 0, 56 - sha->block_size);
    for (unsigned i = 0; i < 8; ++i) {
        sha->block[63 - i] = (unsigned char)(bit_count >> (i * 8));
    }
    sha256_transform(sha, sha->block);
    for (size_t i = 0; i < 8; ++i) {
        digest[i * 4] = (unsigned char)(sha->state[i] >> 24);
        digest[i * 4 + 1] = (unsigned char)(sha->state[i] >> 16);
        digest[i * 4 + 2] = (unsigned char)(sha->state[i] >> 8);
        digest[i * 4 + 3] = (unsigned char)sha->state[i];
    }
}

static void sha256_bytes(const unsigned char *data, size_t size,
                         unsigned char digest[32])
{
    Sha256 sha;
    sha256_init(&sha);
    sha256_update(&sha, data, size);
    sha256_final(&sha, digest);
}

static void digest_hex(const unsigned char digest[32], char out[65])
{
    static const char digits[] = "0123456789abcdef";
    for (size_t i = 0; i < 32; ++i) {
        out[i * 2] = digits[digest[i] >> 4];
        out[i * 2 + 1] = digits[digest[i] & 15];
    }
    out[64] = '\0';
}

static void put_u64_le(unsigned char out[8], uint64_t value)
{
    for (unsigned i = 0; i < 8; ++i) {
        out[i] = (unsigned char)(value >> (i * 8));
    }
}

static uint64_t get_u64_le(const unsigned char in[8])
{
    uint64_t value = 0;
    for (unsigned i = 0; i < 8; ++i) {
        value |= (uint64_t)in[i] << (i * 8);
    }
    return value;
}

static size_t encode_uleb128(uint64_t value, unsigned char out[10])
{
    size_t size = 0;
    do {
        unsigned char byte = (unsigned char)(value & 0x7f);
        value >>= 7;
        if (value != 0) {
            byte |= 0x80;
        }
        out[size++] = byte;
    } while (value != 0);
    return size;
}

static int decode_uleb128(const unsigned char *bytes, size_t size,
                          size_t *position, uint64_t *value)
{
    uint64_t result = 0;
    unsigned shift = 0;
    for (unsigned count = 0; count < 10; ++count) {
        unsigned char byte;
        if (*position >= size) {
            return -1;
        }
        byte = bytes[(*position)++];
        if (shift == 63 && (byte & 0x7e) != 0) {
            return -1;
        }
        result |= (uint64_t)(byte & 0x7f) << shift;
        if ((byte & 0x80) == 0) {
            *value = result;
            return 0;
        }
        shift += 7;
    }
    return -1;
}

static int sqlite_exec_checked(sqlite3 *db, const char *sql)
{
    char *message = NULL;
    int rc = sqlite3_exec(db, sql, NULL, NULL, &message);
    if (rc != SQLITE_OK) {
        fprintf(stderr, "sqlite: %s\n", message == NULL ? sqlite3_errmsg(db) : message);
        sqlite3_free(message);
        return -1;
    }
    return 0;
}

static int ensure_token_capacity(Engine *engine, size_t needed)
{
    size_t old_capacity;
    if (needed <= engine->token_capacity) {
        return 0;
    }
    old_capacity = engine->token_capacity;
    engine->token_capacity = old_capacity == 0 ? 512 : old_capacity;
    while (engine->token_capacity < needed) {
        if (engine->token_capacity > SIZE_MAX / 2) {
            return -1;
        }
        engine->token_capacity *= 2;
    }
    engine->tokens = xrealloc(engine->tokens,
                              engine->token_capacity * sizeof(*engine->tokens));
    memset(engine->tokens + old_capacity, 0,
           (engine->token_capacity - old_capacity) * sizeof(*engine->tokens));
    return 0;
}

static void token_order_append(Engine *engine, uint64_t token_id)
{
    if (engine->token_order_count == engine->token_order_capacity) {
        engine->token_order_capacity = engine->token_order_capacity == 0 ? 512 :
                                       engine->token_order_capacity * 2;
        engine->token_order = xrealloc(engine->token_order,
            engine->token_order_capacity * sizeof(*engine->token_order));
    }
    engine->tokens[token_id].order_position = engine->token_order_count;
    engine->token_order[engine->token_order_count++] = token_id;
}

static int append_token_resident(Engine *engine, uint64_t token_id,
                                 const unsigned char *segment, size_t segment_size,
                                 uint64_t writes, uint64_t reads, uint64_t usage,
                                 bool has_children, uint64_t left, uint64_t right)
{
    Token *token;
    uint64_t hash;
    if (token_id != engine->token_count || token_id > SIZE_MAX || segment_size == 0 ||
        ensure_token_capacity(engine, (size_t)token_id + 1) != 0) {
        return -1;
    }
    token = &engine->tokens[token_id];
    token->segment = xmalloc(segment_size);
    memcpy(token->segment, segment, segment_size);
    token->segment_size = segment_size;
    token->write_count = writes;
    token->read_count = reads;
    token->usage_count = usage;
    token->order_write_count = writes;
    token->order_read_count = reads;
    token->order_usage_count = usage;
    token->has_children = has_children;
    token->left = left;
    token->right = right;
    ++engine->token_count;
    token_order_append(engine, token_id);
    hash = hash_bytes(segment, segment_size);
    segment_map_insert(&engine->segments, hash, token_id);
    return trie_insert(engine->trie, segment, segment_size, token_id);
}

static int insert_token_row(Engine *engine, uint64_t token_id,
                            const unsigned char *segment, size_t segment_size)
{
    sqlite3_stmt *statement = NULL;
    int rc;
    if (token_id > INT64_MAX || segment_size > INT_MAX) {
        fputs("token registry exhausted SQLite limits\n", stderr);
        return -1;
    }
    rc = sqlite3_prepare_v2(engine->db,
        "INSERT INTO token_translation(token_id,segment,write_count,read_count,usage_count,"
        "left_id,right_id) VALUES(?1,?2,zeroblob(8),zeroblob(8),zeroblob(8),NULL,NULL)",
        -1, &statement, NULL);
    if (rc == SQLITE_OK) rc = sqlite3_bind_int64(statement, 1, (sqlite3_int64)token_id);
    if (rc == SQLITE_OK) rc = sqlite3_bind_blob(statement, 2, segment,
                                                 (int)segment_size, SQLITE_STATIC);
    if (rc == SQLITE_OK) rc = sqlite3_step(statement);
    if (rc != SQLITE_DONE) {
        fprintf(stderr, "insert token: %s\n", sqlite3_errmsg(engine->db));
        sqlite3_finalize(statement);
        return -1;
    }
    sqlite3_finalize(statement);
    return 0;
}

static int get_or_create_token(Engine *engine, const unsigned char *segment,
                               size_t segment_size, uint64_t *token_id)
{
    uint64_t hash;
    uint64_t found;
    if (segment_size == 0) {
        return -1;
    }
    hash = hash_bytes(segment, segment_size);
    found = segment_map_find(engine, segment, segment_size, hash);
    if (found != NO_TOKEN) {
        *token_id = found;
        return 0;
    }
    if (engine->token_count >= (size_t)INT64_MAX) {
        return -1;
    }
    *token_id = engine->token_count;
    if (insert_token_row(engine, *token_id, segment, segment_size) != 0 ||
        append_token_resident(engine, *token_id, segment, segment_size,
                              0, 0, 0, false, 0, 0) != 0) {
        return -1;
    }
    return 0;
}

static int get_or_create_composite(Engine *engine, uint64_t left, uint64_t right,
                                   uint64_t *token_id)
{
    Token *a = &engine->tokens[left];
    Token *b = &engine->tokens[right];
    unsigned char *combined;
    size_t size;
    int rc;
    if (a->segment_size > SIZE_MAX - b->segment_size) {
        return -1;
    }
    size = a->segment_size + b->segment_size;
    combined = xmalloc(size);
    memcpy(combined, a->segment, a->segment_size);
    memcpy(combined + a->segment_size, b->segment, b->segment_size);
    rc = get_or_create_token(engine, combined, size, token_id);
    free(combined);
    if (rc == 0 && !engine->tokens[*token_id].has_children) {
        sqlite3_stmt *statement = NULL;
        rc = sqlite3_prepare_v2(engine->db,
            "UPDATE token_translation SET left_id=?1,right_id=?2 "
            "WHERE token_id=?3 AND left_id IS NULL AND right_id IS NULL",
            -1, &statement, NULL);
        if (rc == SQLITE_OK) rc = sqlite3_bind_int64(statement, 1, (sqlite3_int64)left);
        if (rc == SQLITE_OK) rc = sqlite3_bind_int64(statement, 2, (sqlite3_int64)right);
        if (rc == SQLITE_OK) rc = sqlite3_bind_int64(statement, 3, (sqlite3_int64)*token_id);
        if (rc == SQLITE_OK) rc = sqlite3_step(statement);
        if (rc != SQLITE_DONE) {
            fprintf(stderr, "persist composite parentage: %s\n", sqlite3_errmsg(engine->db));
            sqlite3_finalize(statement);
            return -1;
        }
        sqlite3_finalize(statement);
        engine->tokens[*token_id].has_children = true;
        engine->tokens[*token_id].left = left;
        engine->tokens[*token_id].right = right;
        rc = 0;
    }
    return rc;
}

static bool composite_segment_matches(const Engine *engine,
                                      const unsigned char *segment, size_t segment_size,
                                      uint64_t left, uint64_t right)
{
    const Token *left_token = &engine->tokens[left];
    const Token *right_token = &engine->tokens[right];
    if (left_token->segment_size > MAX_MEMORY_BLOB_BYTES ||
        right_token->segment_size > MAX_MEMORY_BLOB_BYTES ||
        left_token->segment_size > MAX_MEMORY_BLOB_BYTES - right_token->segment_size ||
        segment_size != left_token->segment_size + right_token->segment_size) {
        return false;
    }
    return memcmp(segment, left_token->segment, left_token->segment_size) == 0 &&
           memcmp(segment + left_token->segment_size, right_token->segment,
                  right_token->segment_size) == 0;
}

static int load_registry(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    int rc;
    engine->trie = trie_node_new();
    rc = sqlite3_prepare_v2(engine->db,
        "SELECT token_id,segment,write_count,read_count,usage_count,left_id,right_id "
        "FROM token_translation ORDER BY token_id", -1, &statement, NULL);
    if (rc != SQLITE_OK) {
        fprintf(stderr, "load token registry: %s\n", sqlite3_errmsg(engine->db));
        return -1;
    }
    while ((rc = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 raw_id = sqlite3_column_int64(statement, 0);
        const unsigned char *segment = sqlite3_column_blob(statement, 1);
        int segment_size = sqlite3_column_bytes(statement, 1);
        const unsigned char *write_blob = sqlite3_column_blob(statement, 2);
        const unsigned char *read_blob = sqlite3_column_blob(statement, 3);
        const unsigned char *usage_blob = sqlite3_column_blob(statement, 4);
        bool has_children = sqlite3_column_type(statement, 5) != SQLITE_NULL;
        sqlite3_int64 left = has_children ? sqlite3_column_int64(statement, 5) : 0;
        sqlite3_int64 right = has_children ? sqlite3_column_int64(statement, 6) : 0;
        if (raw_id < 0 || sqlite3_column_bytes(statement, 2) != 8 ||
            sqlite3_column_bytes(statement, 3) != 8 ||
            sqlite3_column_bytes(statement, 4) != 8 ||
            (sqlite3_column_type(statement, 5) == SQLITE_NULL) !=
                (sqlite3_column_type(statement, 6) == SQLITE_NULL) ||
            left < 0 || right < 0 ||
            (has_children && ((uint64_t)left >= (uint64_t)raw_id ||
                              (uint64_t)right >= (uint64_t)raw_id)) ||
            (uint64_t)raw_id != engine->token_count || segment_size <= 0 ||
            (size_t)segment_size > MAX_MEMORY_BLOB_BYTES ||
            (has_children && !composite_segment_matches(engine, segment,
                (size_t)segment_size, (uint64_t)left, (uint64_t)right)) ||
            append_token_resident(engine, (uint64_t)raw_id, segment,
                (size_t)segment_size, get_u64_le(write_blob), get_u64_le(read_blob),
                get_u64_le(usage_blob), has_children,
                (uint64_t)left, (uint64_t)right) != 0) {
            fputs("invalid token registry\n", stderr);
            sqlite3_finalize(statement);
            return -1;
        }
    }
    sqlite3_finalize(statement);
    if (rc != SQLITE_DONE || engine->token_count < 256) {
        fputs("incomplete token registry\n", stderr);
        return -1;
    }
    for (size_t i = 0; i < 256; ++i) {
        if (engine->tokens[i].segment_size != 1 || engine->tokens[i].segment[0] != i) {
            fprintf(stderr, "foundational token %zu is corrupt\n", i);
            return -1;
        }
    }
    return 0;
}

static int initialize_store(const char *store_path)
{
    char *memory_dir = NULL;
    char *catalog_path = NULL;
    sqlite3 *db = NULL;
    sqlite3_stmt *statement = NULL;
    int rc = -1;

    if (mkdir(store_path, 0700) != 0) {
        fprintf(stderr, "create store %s: %s\n", store_path, strerror(errno));
        return -1;
    }
    memory_dir = path_join(store_path, "memories");
    if (mkdir(memory_dir, 0700) != 0) {
        fprintf(stderr, "create memory directory: %s\n", strerror(errno));
        goto done;
    }
    catalog_path = path_join(store_path, "catalog.sqlite3");
    if (sqlite3_open_v2(catalog_path, &db,
                        SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE, NULL) != SQLITE_OK) {
        fprintf(stderr, "create token catalog: %s\n",
                db == NULL ? "open failed" : sqlite3_errmsg(db));
        goto done;
    }
    if (sqlite_exec_checked(db, "PRAGMA journal_mode=WAL;") != 0 ||
        sqlite_exec_checked(db, "PRAGMA synchronous=FULL;") != 0 ||
        sqlite_exec_checked(db,
            "CREATE TABLE token_translation("
            "token_id INTEGER PRIMARY KEY,segment BLOB NOT NULL UNIQUE,"
            "write_count BLOB NOT NULL CHECK(length(write_count)=8),"
            "read_count BLOB NOT NULL CHECK(length(read_count)=8),"
            "usage_count BLOB NOT NULL CHECK(length(usage_count)=8),"
            "left_id INTEGER NULL,right_id INTEGER NULL,"
            "CHECK((left_id IS NULL)=(right_id IS NULL))) WITHOUT ROWID;"
            "CREATE TABLE memory_accounting("
            "memory_id INTEGER PRIMARY KEY,tier TEXT NOT NULL,"
            "content_sha256 BLOB NOT NULL CHECK(length(content_sha256)=32),"
            "metadata_sha256 BLOB NOT NULL CHECK(length(metadata_sha256)=32));"
            "CREATE TABLE neutral_accounting_intent("
            "memory_id INTEGER PRIMARY KEY,tier TEXT NOT NULL,"
            "content_sha256 BLOB NOT NULL CHECK(length(content_sha256)=32),"
            "metadata_sha256 BLOB NOT NULL CHECK(length(metadata_sha256)=32));"
            "CREATE TABLE pair_observation("
            "left_id INTEGER NOT NULL,right_id INTEGER NOT NULL,"
            "observations BLOB NOT NULL CHECK(length(observations)=8),"
            "PRIMARY KEY(left_id,right_id)) WITHOUT ROWID;"
            "CREATE TABLE link_reinforcement("
            "source_id INTEGER NOT NULL,target_id INTEGER NOT NULL,"
            "reinforcement INTEGER NOT NULL,"
            "PRIMARY KEY(source_id,target_id)) WITHOUT ROWID;"
            "CREATE TABLE memory_state("
            "memory_id INTEGER PRIMARY KEY,"
            "access_count BLOB NOT NULL CHECK(length(access_count)=8)) WITHOUT ROWID;"
            "CREATE TABLE operation_receipt("
            "op_key TEXT PRIMARY KEY,request_sha256 BLOB NOT NULL "
            "CHECK(length(request_sha256)=32),kind TEXT NOT NULL,"
            "memory_id INTEGER NOT NULL,state INTEGER NOT NULL "
            "CHECK(state IN (0,1))) WITHOUT ROWID;"
            "CREATE INDEX operation_receipt_memory_id "
            "ON operation_receipt(memory_id)") != 0 ||
        sqlite_exec_checked(db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(db,
            "INSERT INTO token_translation VALUES("
            "?1,?2,zeroblob(8),zeroblob(8),zeroblob(8),NULL,NULL)",
            -1, &statement, NULL) != SQLITE_OK) {
        goto done;
    }
    for (int i = 0; i < 256; ++i) {
        unsigned char byte = (unsigned char)i;
        sqlite3_bind_int(statement, 1, i);
        sqlite3_bind_blob(statement, 2, &byte, 1, SQLITE_TRANSIENT);
        if (sqlite3_step(statement) != SQLITE_DONE) {
            fprintf(stderr, "insert foundational token: %s\n", sqlite3_errmsg(db));
            goto rollback;
        }
        sqlite3_reset(statement);
        sqlite3_clear_bindings(statement);
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite_exec_checked(db, "COMMIT") != 0) {
        goto rollback;
    }
    rc = 0;
    goto done;

rollback:
    sqlite3_finalize(statement);
    statement = NULL;
    sqlite_exec_checked(db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    if (db != NULL) sqlite3_close(db);
    free(memory_dir);
    free(catalog_path);
    if (rc == 0) {
        fprintf(stderr, "initialized %s with 256 foundational tokens\n", store_path);
    }
    return rc;
}

static int encode_greedy(const Engine *engine, const unsigned char *bytes, size_t size,
                         TokenVector *tokens)
{
    size_t position = 0;
    while (position < size) {
        TrieNode *node = engine->trie;
        uint64_t best = NO_TOKEN;
        size_t best_end = position;
        size_t cursor = position;
        while (cursor < size && (node = trie_child(node, bytes[cursor], false)) != NULL) {
            ++cursor;
            if (node->token_id != NO_TOKEN) {
                best = node->token_id;
                best_end = cursor;
            }
        }
        if (best == NO_TOKEN) {
            fputs("foundational byte token is missing\n", stderr);
            return -1;
        }
        token_vector_push(tokens, best);
        position = best_end;
    }
    return 0;
}

static int crystallize_online(Engine *engine, TokenVector *sequence,
                              PairMap *pair_deltas)
{
    size_t read = 0;
    size_t write = 0;
    while (read < sequence->count) {
        uint64_t current = sequence->items[read++];
        while (read < sequence->count) {
            uint64_t right = sequence->items[read];
            PairSlot *pair = pair_map_find(&engine->pairs, current, right);
            uint64_t observations = pair_delta_observe(pair_deltas, current, right);
            observations = saturating_add_u64(
                pair == NULL ? 0 : pair->observations, observations);
            if (observations < FORMATION_THRESHOLD) break;
            {
                uint64_t composite;
                if (get_or_create_composite(engine, current, right, &composite) != 0)
                    return -1;
                current = composite;
            }
            ++read;
        }
        sequence->items[write++] = current;
    }
    sequence->count = write;
    return 0;
}

static int encode_memory_blob(const TokenVector *tokens, unsigned char **result,
                              size_t *result_size)
{
    Buffer buffer = {0};
    unsigned char count_bytes[8];
    buffer_append(&buffer, MEMORY_MAGIC, 8);
    put_u64_le(count_bytes, (uint64_t)tokens->count);
    buffer_append(&buffer, count_bytes, sizeof(count_bytes));
    for (size_t i = 0; i < tokens->count; ++i) {
        unsigned char encoded[10];
        size_t size = encode_uleb128(tokens->items[i], encoded);
        if (buffer.count > MAX_MEMORY_BLOB_BYTES - size) {
            free(buffer.items);
            return -1;
        }
        buffer_append(&buffer, encoded, size);
    }
    *result = buffer.items;
    *result_size = buffer.count;
    return 0;
}

static int decode_memory_blob(const Engine *engine, const unsigned char *bytes,
                              size_t size, TokenVector *tokens)
{
    uint64_t count;
    size_t position = MEMORY_HEADER_SIZE;
    size_t decoded_size = 0;
    if (size < MEMORY_HEADER_SIZE || memcmp(bytes, MEMORY_MAGIC, 8) != 0) {
        return -1;
    }
    count = get_u64_le(bytes + 8);
    if (size > MAX_MEMORY_BLOB_BYTES ||
        count > size - MEMORY_HEADER_SIZE ||
        count > SIZE_MAX / sizeof(*tokens->items)) {
        return -1;
    }
    tokens->items = xmalloc((size_t)count * sizeof(*tokens->items));
    tokens->capacity = (size_t)count;
    for (uint64_t i = 0; i < count; ++i) {
        uint64_t token_id;
        unsigned char canonical[10];
        size_t start = position;
        size_t canonical_size;
        if (decode_uleb128(bytes, size, &position, &token_id) != 0 ||
            token_id >= engine->token_count) {
            goto invalid;
        }
        if (engine->tokens[token_id].segment_size >
            MAX_MEMORY_BLOB_BYTES - decoded_size) {
            goto invalid;
        }
        decoded_size += engine->tokens[token_id].segment_size;
        canonical_size = encode_uleb128(token_id, canonical);
        if (position - start != canonical_size ||
            memcmp(bytes + start, canonical, canonical_size) != 0) {
            goto invalid;
        }
        tokens->items[tokens->count++] = token_id;
    }
    if (position != size) {
        goto invalid;
    }
    return 0;

invalid:
    free(tokens->items);
    memset(tokens, 0, sizeof(*tokens));
    return -1;
}

static int decode_tokens_to_bytes(const Engine *engine, const TokenVector *tokens,
                                  unsigned char **result, size_t *result_size)
{
    Buffer buffer = {0};
    for (size_t i = 0; i < tokens->count; ++i) {
        const Token *token;
        if (tokens->items[i] >= engine->token_count) {
            free(buffer.items);
            return -1;
        }
        token = &engine->tokens[tokens->items[i]];
        if (token->segment_size > MAX_MEMORY_BLOB_BYTES - buffer.count) {
            free(buffer.items);
            return -1;
        }
        buffer_append(&buffer, token->segment, token->segment_size);
    }
    *result = buffer.items;
    *result_size = buffer.count;
    return 0;
}

static int safe_name(const unsigned char *name, size_t size)
{
    if (size == 0 || size > 100 ||
        (size == 1 && name[0] == '.') ||
        (size == 2 && name[0] == '.' && name[1] == '.')) {
        return -1;
    }
    for (size_t i = 0; i < size; ++i) {
        if (!isalnum(name[i]) && name[i] != '-' && name[i] != '_' && name[i] != '.') {
            return -1;
        }
    }
    return 0;
}

static int parse_memory_id(const char *text, int64_t *id)
{
    char *end = NULL;
    long long value;
    if (*text == '\0' || (*text == '0' && text[1] != '\0')) {
        return -1;
    }
    errno = 0;
    value = strtoll(text, &end, 10);
    if (errno != 0 || *end != '\0' || value <= 0) {
        return -1;
    }
    *id = (int64_t)value;
    return 0;
}

static void bytes_free(Bytes *bytes)
{
    free(bytes->data);
    memset(bytes, 0, sizeof(*bytes));
}

static Bytes bytes_copy(const unsigned char *data, size_t size)
{
    Bytes result = {0};
    result.data = xmalloc(size + 1);
    memcpy(result.data, data, size);
    result.data[size] = '\0';
    result.size = size;
    return result;
}

static void metadata_free(Metadata *metadata)
{
    bytes_free(&metadata->created_at);
    bytes_free(&metadata->updated_at);
    bytes_free(&metadata->tier);
    bytes_free(&metadata->status);
    bytes_free(&metadata->expires_at);
    memset(metadata, 0, sizeof(*metadata));
}

static void append_hex_field(Buffer *buffer, const Bytes *field)
{
    static const char digits[] = "0123456789abcdef";
    buffer_printf(buffer, "%zu", field->size);
    if (field->size != 0) {
        buffer_append_text(buffer, " ");
        for (size_t i = 0; i < field->size; ++i) {
            char pair[2] = { digits[field->data[i] >> 4], digits[field->data[i] & 15] };
            buffer_append(buffer, pair, sizeof(pair));
        }
    }
    buffer_append_text(buffer, "\n");
}

static void append_metadata(Buffer *buffer, const Metadata *metadata)
{
    uint64_t confidence_bits;
    memcpy(&confidence_bits, &metadata->confidence, sizeof(confidence_bits));
    buffer_printf(buffer, "%" PRId64 "\n", metadata->id);
    append_hex_field(buffer, &metadata->created_at);
    append_hex_field(buffer, &metadata->updated_at);
    append_hex_field(buffer, &metadata->tier);
    buffer_printf(buffer, "%016" PRIx64 "\n", confidence_bits);
    append_hex_field(buffer, &metadata->status);
    if (metadata->has_source_event_id) buffer_printf(buffer, "%" PRId64 "\n", metadata->source_event_id);
    else buffer_append_text(buffer, "-\n");
    if (metadata->has_source_memory_id) buffer_printf(buffer, "%" PRId64 "\n", metadata->source_memory_id);
    else buffer_append_text(buffer, "-\n");
    if (metadata->has_supersedes_id) buffer_printf(buffer, "%" PRId64 "\n", metadata->supersedes_id);
    else buffer_append_text(buffer, "-\n");
    if (metadata->has_expires_at) append_hex_field(buffer, &metadata->expires_at);
    else buffer_append_text(buffer, "-\n");
    /* Omit the extension for legacy records to preserve their exact digests. */
    if (metadata->source_event_kind == SOURCE_EVENT_EXECUTIVE)
        buffer_append_text(buffer, "event\n");
    else if (metadata->source_event_kind == SOURCE_EVENT_SENSE)
        buffer_append_text(buffer, "sense_event\n");
}

static int metadata_encode(const Metadata *metadata, unsigned char **result,
                           size_t *result_size)
{
    Buffer buffer = {0};
    append_metadata(&buffer, metadata);
    *result = buffer.items;
    *result_size = buffer.count;
    return 0;
}

static int hex_value(unsigned char c)
{
    if (c >= '0' && c <= '9') return c - '0';
    if (c >= 'a' && c <= 'f') return c - 'a' + 10;
    return -1;
}

static int parse_hex_field(const unsigned char *line, size_t size, Bytes *field)
{
    size_t position = 0;
    size_t byte_count = 0;
    if (size == 0 || !isdigit(line[0])) return -1;
    while (position < size && isdigit(line[position])) {
        unsigned digit = line[position++] - '0';
        if (byte_count > (SIZE_MAX - digit) / 10) return -1;
        byte_count = byte_count * 10 + digit;
    }
    if (position > 1 && line[0] == '0') return -1;
    if (byte_count == 0) {
        if (position != size) return -1;
        *field = bytes_copy((const unsigned char *)"", 0);
        return 0;
    }
    if (position >= size || line[position++] != ' ' ||
        byte_count > (SIZE_MAX - position) / 2 || size - position != byte_count * 2) {
        return -1;
    }
    field->data = xmalloc(byte_count + 1);
    field->size = byte_count;
    for (size_t i = 0; i < byte_count; ++i) {
        int high = hex_value(line[position + i * 2]);
        int low = hex_value(line[position + i * 2 + 1]);
        if (high < 0 || low < 0) {
            bytes_free(field);
            return -1;
        }
        field->data[i] = (unsigned char)((high << 4) | low);
    }
    field->data[byte_count] = '\0';
    return 0;
}

static int parse_i64_line(const unsigned char *line, size_t size, int64_t *value,
                          bool positive_only)
{
    char text[64];
    char canonical[64];
    char *end = NULL;
    long long parsed;
    int canonical_size;
    if (size == 0 || size >= sizeof(text)) return -1;
    memcpy(text, line, size);
    text[size] = '\0';
    errno = 0;
    parsed = strtoll(text, &end, 10);
    if (errno != 0 || *end != '\0' || parsed < INT64_MIN || parsed > INT64_MAX ||
        (positive_only && parsed <= 0)) return -1;
    canonical_size = snprintf(canonical, sizeof(canonical), "%" PRId64,
                              (int64_t)parsed);
    if (canonical_size < 0 || (size_t)canonical_size != size ||
        memcmp(text, canonical, size) != 0) return -1;
    *value = (int64_t)parsed;
    return 0;
}

static int parse_nullable_i64(const unsigned char *line, size_t size,
                              bool *present, int64_t *value)
{
    if (size == 1 && line[0] == '-') {
        *present = false;
        return 0;
    }
    if (parse_i64_line(line, size, value, false) != 0) return -1;
    *present = true;
    return 0;
}

static int parse_source_event_kind(const unsigned char *bytes, size_t size,
                                   SourceEventKind *kind)
{
    if (size == 5 && memcmp(bytes, "event", 5) == 0)
        *kind = SOURCE_EVENT_EXECUTIVE;
    else if (size == 11 && memcmp(bytes, "sense_event", 11) == 0)
        *kind = SOURCE_EVENT_SENSE;
    else
        return -1;
    return 0;
}

static int metadata_decode(const unsigned char *bytes, size_t size, Metadata *metadata,
                           bool allow_zero_id)
{
    const unsigned char *lines[10];
    size_t lengths[10];
    size_t start = 0;
    uint64_t confidence_bits = 0;

    memset(metadata, 0, sizeof(*metadata));
    for (size_t line = 0; line < 10; ++line) {
        size_t end = start;
        while (end < size && bytes[end] != '\n') ++end;
        if (end == size) goto invalid;
        lines[line] = bytes + start;
        lengths[line] = end - start;
        start = end + 1;
    }
    if (parse_i64_line(lines[0], lengths[0], &metadata->id, !allow_zero_id) != 0 ||
        (allow_zero_id && metadata->id < 0) ||
        parse_hex_field(lines[1], lengths[1], &metadata->created_at) != 0 ||
        parse_hex_field(lines[2], lengths[2], &metadata->updated_at) != 0 ||
        parse_hex_field(lines[3], lengths[3], &metadata->tier) != 0 ||
        lengths[4] != 16 || parse_hex_field(lines[5], lengths[5], &metadata->status) != 0 ||
        parse_nullable_i64(lines[6], lengths[6], &metadata->has_source_event_id,
                           &metadata->source_event_id) != 0 ||
        parse_nullable_i64(lines[7], lengths[7], &metadata->has_source_memory_id,
                           &metadata->source_memory_id) != 0 ||
        parse_nullable_i64(lines[8], lengths[8], &metadata->has_supersedes_id,
                           &metadata->supersedes_id) != 0) {
        goto invalid;
    }
    for (size_t i = 0; i < 16; ++i) {
        int nibble = hex_value(lines[4][i]);
        if (nibble < 0) goto invalid;
        confidence_bits = (confidence_bits << 4) | (unsigned)nibble;
    }
    memcpy(&metadata->confidence, &confidence_bits, sizeof(confidence_bits));
    if (lengths[9] == 1 && lines[9][0] == '-') {
        metadata->has_expires_at = false;
    } else {
        if (parse_hex_field(lines[9], lengths[9], &metadata->expires_at) != 0) goto invalid;
        metadata->has_expires_at = true;
    }
    if (start < size &&
        (bytes[size - 1] != '\n' ||
         parse_source_event_kind(bytes + start, size - start - 1,
                                  &metadata->source_event_kind) != 0 ||
         !metadata->has_source_event_id || metadata->source_event_id <= 0))
        goto invalid;
    if (safe_name(metadata->tier.data, metadata->tier.size) != 0) goto invalid;
    return 0;

invalid:
    metadata_free(metadata);
    return -1;
}

static int safe_read_regular(const char *path, unsigned char **result, size_t *result_size)
{
    struct stat before;
    struct stat after;
    /* A path replaced by a FIFO must reach fstat instead of blocking in open. */
    int flags = O_RDONLY | O_CLOEXEC | O_NONBLOCK;
    int fd;
    int rc = -1;
    if (lstat(path, &before) != 0 || !S_ISREG(before.st_mode)) {
        fprintf(stderr, "%s is not a regular file\n", path);
        return -1;
    }
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif
    fd = open(path, flags);
    if (fd < 0) {
        fprintf(stderr, "open %s: %s\n", path, strerror(errno));
        return -1;
    }
    if (fstat(fd, &after) != 0 || !S_ISREG(after.st_mode) ||
        after.st_dev != before.st_dev || after.st_ino != before.st_ino ||
        after.st_size < 0 || (uintmax_t)after.st_size > SIZE_MAX ||
        (uintmax_t)after.st_size > MAX_MEMORY_BLOB_BYTES) {
        fprintf(stderr, "%s changed or is not a regular file\n", path);
        goto done;
    }
    *result = xmalloc((size_t)after.st_size);
    if (read_exact(fd, *result, (size_t)after.st_size) != 0) {
        fprintf(stderr, "read %s: %s\n", path, strerror(errno));
        free(*result);
        *result = NULL;
        goto done;
    }
    *result_size = (size_t)after.st_size;
    rc = 0;
done:
    if (close(fd) != 0 && rc == 0) {
        free(*result);
        *result = NULL;
        *result_size = 0;
        rc = -1;
    }
    return rc;
}

static int write_file_sync(const char *path, const unsigned char *bytes, size_t size)
{
    int fd = open(path, O_WRONLY | O_CREAT | O_EXCL | O_CLOEXEC, 0600);
    int rc = -1;
    if (fd < 0) {
        fprintf(stderr, "create %s: %s\n", path, strerror(errno));
        return -1;
    }
    if (write_all(fd, bytes, size) != 0 || fsync(fd) != 0) {
        fprintf(stderr, "write %s: %s\n", path, strerror(errno));
        goto done;
    }
    rc = 0;
done:
    if (close(fd) != 0 && rc == 0) rc = -1;
    return rc;
}

static int fsync_directory(const char *path)
{
    int fd = open(path, O_RDONLY | O_DIRECTORY | O_CLOEXEC);
    int rc;
    if (fd < 0) return -1;
    rc = fsync(fd);
    if (close(fd) != 0 && rc == 0) rc = -1;
    return rc;
}

static int ensure_real_directory(const char *path, bool *created)
{
    struct stat st;
    *created = false;
    if (lstat(path, &st) == 0) {
        if (!S_ISDIR(st.st_mode)) {
            fprintf(stderr, "%s is not a real directory\n", path);
            return -1;
        }
        return 0;
    }
    if (errno != ENOENT || mkdir(path, 0700) != 0) {
        fprintf(stderr, "create directory %s: %s\n", path, strerror(errno));
        return -1;
    }
    *created = true;
    return 0;
}

static int manifest_for_digests(const unsigned char content_digest[32],
                                const unsigned char metadata_digest[32],
                                Buffer *manifest)
{
    char hex[65];
    digest_hex(content_digest, hex);
    buffer_printf(manifest, "%s  content.memory\n", hex);
    digest_hex(metadata_digest, hex);
    buffer_printf(manifest, "%s  metadata.memory\n", hex);
    return 0;
}

static int manifest_for_blobs(const unsigned char *content, size_t content_size,
                              const unsigned char *metadata, size_t metadata_size,
                              Buffer *manifest)
{
    unsigned char content_digest[32];
    unsigned char metadata_digest[32];
    sha256_bytes(content, content_size, content_digest);
    sha256_bytes(metadata, metadata_size, metadata_digest);
    return manifest_for_digests(content_digest, metadata_digest, manifest);
}

static int verify_manifest_bytes(const unsigned char *manifest, size_t manifest_size,
                                 const unsigned char *content, size_t content_size,
                                 const unsigned char *metadata, size_t metadata_size)
{
    Buffer expected = {0};
    int rc;
    manifest_for_blobs(content, content_size, metadata, metadata_size, &expected);
    rc = expected.count == manifest_size &&
         memcmp(expected.items, manifest, manifest_size) == 0 ? 0 : -1;
    free(expected.items);
    return rc;
}

static int record_paths(const Engine *engine, const char *tier, const char *key,
                        char **record_dir, char **content_path, char **metadata_path,
                        char **manifest_path)
{
    char *tier_dir = path_join(engine->memory_dir, tier);
    *record_dir = path_join(tier_dir, key);
    *content_path = path_join(*record_dir, "content.memory");
    *metadata_path = path_join(*record_dir, "metadata.memory");
    *manifest_path = path_join(*record_dir, "manifest.sha256");
    free(tier_dir);
    return 0;
}

static int read_record_blobs(const Engine *engine, const char *tier, const char *key,
                             unsigned char **content, size_t *content_size,
                             unsigned char **metadata, size_t *metadata_size)
{
    char *record_dir = NULL;
    char *content_path = NULL;
    char *metadata_path = NULL;
    char *manifest_path = NULL;
    unsigned char *manifest = NULL;
    size_t manifest_size = 0;
    int rc = -1;
    record_paths(engine, tier, key, &record_dir, &content_path,
                 &metadata_path, &manifest_path);
    if (safe_read_regular(content_path, content, content_size) != 0 ||
        safe_read_regular(metadata_path, metadata, metadata_size) != 0 ||
        safe_read_regular(manifest_path, &manifest, &manifest_size) != 0 ||
        verify_manifest_bytes(manifest, manifest_size, *content, *content_size,
                              *metadata, *metadata_size) != 0) {
        fprintf(stderr, "checksum validation failed for %s/%s\n", tier, key);
        goto done;
    }
    rc = 0;
done:
    if (rc != 0) {
        free(*content);
        free(*metadata);
        *content = NULL;
        *metadata = NULL;
        *content_size = 0;
        *metadata_size = 0;
    }
    free(manifest);
    free(record_dir);
    free(content_path);
    free(metadata_path);
    free(manifest_path);
    return rc;
}

static int publish_record_files(Engine *engine, const char *tier, const char *key,
                                const unsigned char *content, size_t content_size,
                                const unsigned char *metadata, size_t metadata_size)
{
    char *tier_dir = path_join(engine->memory_dir, tier);
    char *final_dir = path_join(tier_dir, key);
    char *template_path = path_join(tier_dir, ".pending-XXXXXXXX");
    char *staged_dir = NULL;
    char *content_path = NULL;
    char *metadata_path = NULL;
    char *manifest_path = NULL;
    Buffer manifest = {0};
    int rc = PUBLISH_CLEAN_FAILURE;
    struct stat st;
    bool tier_created = false;
    bool publication_ambiguous = false;

    if (ensure_real_directory(tier_dir, &tier_created) != 0) goto done;
    (void)tier_created;
    if (fsync_directory(engine->memory_dir) != 0) {
        fprintf(stderr, "sync new tier %s: %s\n", tier, strerror(errno));
        engine->poisoned = true;
        goto done;
    }
    if (lstat(final_dir, &st) == 0 || errno != ENOENT) {
        fprintf(stderr, "memory %s/%s already exists\n", tier, key);
        engine->poisoned = true;
        goto done;
    }
    staged_dir = mkdtemp(template_path);
    if (staged_dir == NULL) {
        fprintf(stderr, "create staging directory: %s\n", strerror(errno));
        goto done;
    }
    content_path = path_join(staged_dir, "content.memory");
    metadata_path = path_join(staged_dir, "metadata.memory");
    manifest_path = path_join(staged_dir, "manifest.sha256");
    manifest_for_blobs(content, content_size, metadata, metadata_size, &manifest);
    if (write_file_sync(content_path, content, content_size) != 0 ||
        write_file_sync(metadata_path, metadata, metadata_size) != 0 ||
        write_file_sync(manifest_path, manifest.items, manifest.count) != 0 ||
        fsync_directory(staged_dir) != 0) {
        fprintf(stderr, "publish memory %s/%s: %s\n", tier, key, strerror(errno));
        goto done;
    }
    if (rename_noreplace(staged_dir, final_dir) != 0) {
        if (errno == EEXIST || errno == ENOTEMPTY) engine->poisoned = true;
        fprintf(stderr, "publish memory %s/%s: %s\n", tier, key, strerror(errno));
        goto done;
    }
    staged_dir = NULL;
    if (fsync_directory(tier_dir) != 0) {
        fprintf(stderr, "sync published memory %s/%s: %s\n", tier, key, strerror(errno));
        if (rename(final_dir, template_path) == 0) {
            staged_dir = template_path;
            if (fsync_directory(tier_dir) != 0) publication_ambiguous = true;
        } else {
            publication_ambiguous = true;
        }
        goto done;
    }
    rc = 0;
done:
    if (publication_ambiguous) rc = PUBLISH_AMBIGUOUS_FAILURE;
    if (staged_dir != NULL) {
        if (content_path != NULL) unlink(content_path);
        if (metadata_path != NULL) unlink(metadata_path);
        if (manifest_path != NULL) unlink(manifest_path);
        rmdir(staged_dir);
    }
    free(manifest.items);
    free(content_path);
    free(metadata_path);
    free(manifest_path);
    free(template_path);
    free(final_dir);
    free(tier_dir);
    return rc;
}

static int publish_metadata_replacement(Engine *engine, const Memory *memory,
                                        const unsigned char *content,
                                        size_t content_size,
                                        const unsigned char *metadata,
                                        size_t metadata_size,
                                        const unsigned char metadata_digest[32])
{
    char *tier_dir = path_join(engine->memory_dir, memory->tier_name);
    char *final_dir = path_join(tier_dir, memory->key);
    char *template_path = path_join(tier_dir, ".pending-XXXXXXXX");
    char *staged_dir = NULL;
    char *content_path = NULL;
    char *metadata_path = NULL;
    char *manifest_path = NULL;
    Buffer manifest = {0};
    bool exchanged = false;
    int rc = PUBLISH_CLEAN_FAILURE;

    staged_dir = mkdtemp(template_path);
    if (staged_dir == NULL) goto done;
    content_path = path_join(staged_dir, "content.memory");
    metadata_path = path_join(staged_dir, "metadata.memory");
    manifest_path = path_join(staged_dir, "manifest.sha256");
    /* Canonical re-encoding of immutable content retains its blob digest. */
    manifest_for_digests(memory->content_digest, metadata_digest, &manifest);
    if (write_file_sync(content_path, content, content_size) != 0 ||
        write_file_sync(metadata_path, metadata, metadata_size) != 0 ||
        write_file_sync(manifest_path, manifest.items, manifest.count) != 0 ||
        fsync_directory(staged_dir) != 0 ||
        rename_exchange(staged_dir, final_dir) != 0) {
        goto done;
    }
    exchanged = true;
    if (fsync_directory(tier_dir) != 0) {
        if (rename_exchange(staged_dir, final_dir) != 0 ||
            fsync_directory(tier_dir) != 0) {
            engine->poisoned = true;
            rc = PUBLISH_AMBIGUOUS_FAILURE;
        }
        goto done;
    }
    rc = 0;

done:
    if (staged_dir != NULL) {
        if (exchanged && rc != 0 && rc != PUBLISH_AMBIGUOUS_FAILURE) {
            exchanged = false;
        }
        if (!exchanged || rc == 0) {
            unlink(content_path == NULL ? "" : content_path);
            unlink(metadata_path == NULL ? "" : metadata_path);
            unlink(manifest_path == NULL ? "" : manifest_path);
            rmdir(staged_dir);
        }
    }
    free(manifest.items);
    free(content_path);
    free(metadata_path);
    free(manifest_path);
    free(template_path);
    free(final_dir);
    free(tier_dir);
    return rc;
}

static void count_sequence_read(Engine *engine, const uint64_t *tokens, size_t count)
{
    for (size_t i = 0; i < count; ++i) {
        Token *token = &engine->tokens[tokens[i]];
        token->read_count = saturating_increment(token->read_count);
        enqueue_order_token(engine, tokens[i]);
        if (!token->counters_dirty) {
            if (engine->dirty_count == engine->dirty_capacity &&
                engine->dirty_head != 0) {
                size_t live = engine->dirty_count - engine->dirty_head;
                memmove(engine->dirty_tokens,
                        engine->dirty_tokens + engine->dirty_head,
                        live * sizeof(*engine->dirty_tokens));
                engine->dirty_head = 0;
                engine->dirty_count = live;
            }
            if (engine->dirty_count == engine->dirty_capacity) {
                engine->dirty_capacity = engine->dirty_capacity == 0 ? 256 :
                                         engine->dirty_capacity * 2;
                engine->dirty_tokens = xrealloc(engine->dirty_tokens,
                    engine->dirty_capacity * sizeof(*engine->dirty_tokens));
            }
            engine->dirty_tokens[engine->dirty_count++] = tokens[i];
            token->counters_dirty = true;
        }
    }
}

static void count_sequence_usage(Engine *engine, const TokenVector *sequence)
{
    for (size_t i = 0; i < sequence->count; ++i) {
        Token *token = &engine->tokens[sequence->items[i]];
        token->usage_count = saturating_increment(token->usage_count);
        enqueue_order_token(engine, sequence->items[i]);
        if (!token->counters_dirty) {
            if (engine->dirty_count == engine->dirty_capacity &&
                engine->dirty_head != 0) {
                size_t live = engine->dirty_count - engine->dirty_head;
                memmove(engine->dirty_tokens,
                        engine->dirty_tokens + engine->dirty_head,
                        live * sizeof(*engine->dirty_tokens));
                engine->dirty_head = 0;
                engine->dirty_count = live;
            }
            if (engine->dirty_count == engine->dirty_capacity) {
                engine->dirty_capacity = engine->dirty_capacity == 0 ? 256 :
                                         engine->dirty_capacity * 2;
                engine->dirty_tokens = xrealloc(engine->dirty_tokens,
                    engine->dirty_capacity * sizeof(*engine->dirty_tokens));
            }
            engine->dirty_tokens[engine->dirty_count++] = sequence->items[i];
            token->counters_dirty = true;
        }
    }
}

static void enqueue_order_link(Engine *engine, uint64_t source, Link *link)
{
    if (!engine->ordering_ready || !engine->order_enqueue_enabled ||
        (link->evidence_and_dirty & LINK_ORDER_DIRTY) != 0) {
        return;
    }
    if (engine->order_link_count == engine->order_link_capacity &&
        engine->order_link_head != 0) {
        size_t live = engine->order_link_count - engine->order_link_head;
        memmove(engine->order_links,
                engine->order_links + engine->order_link_head,
                live * sizeof(*engine->order_links));
        engine->order_link_head = 0;
        engine->order_link_count = live;
    }
    if (engine->order_link_count == engine->order_link_capacity) {
        engine->order_link_capacity = engine->order_link_capacity == 0 ? 256 :
                                      engine->order_link_capacity * 2;
        engine->order_links = xrealloc(engine->order_links,
            engine->order_link_capacity * sizeof(*engine->order_links));
    }
    engine->order_links[engine->order_link_count++] =
        (EdgeKey){source, link->target};
    link->evidence_and_dirty |= LINK_ORDER_DIRTY;
}

static void increment_link_reinforcement(Engine *engine, uint64_t source, Link *link)
{
    if ((link->evidence_and_dirty & LINK_PERSIST_DIRTY) == 0) {
        if (engine->dirty_link_count == engine->dirty_link_capacity &&
            engine->dirty_link_head != 0) {
            size_t live = engine->dirty_link_count - engine->dirty_link_head;
            memmove(engine->dirty_links,
                    engine->dirty_links + engine->dirty_link_head,
                    live * sizeof(*engine->dirty_links));
            engine->dirty_link_head = 0;
            engine->dirty_link_count = live;
        }
        if (engine->dirty_link_count == engine->dirty_link_capacity) {
            engine->dirty_link_capacity = engine->dirty_link_capacity == 0 ? 256 :
                                          engine->dirty_link_capacity * 2;
            engine->dirty_links = xrealloc(engine->dirty_links,
                engine->dirty_link_capacity * sizeof(*engine->dirty_links));
        }
        engine->dirty_links[engine->dirty_link_count++] =
            (EdgeKey){source, link->target};
        link->evidence_and_dirty |= LINK_PERSIST_DIRTY;
    }
    if (link->reinforcement != UINT32_MAX) ++link->reinforcement;
    enqueue_order_link(engine, source, link);
}

static void increment_memory_access(Engine *engine, Memory *memory)
{
    memory->access_count = saturating_increment(memory->access_count);
    if (memory->access_dirty) return;
    if (engine->dirty_memory_count == engine->dirty_memory_capacity &&
        engine->dirty_memory_head != 0) {
        size_t live = engine->dirty_memory_count - engine->dirty_memory_head;
        memmove(engine->dirty_memories,
                engine->dirty_memories + engine->dirty_memory_head,
                live * sizeof(*engine->dirty_memories));
        engine->dirty_memory_head = 0;
        engine->dirty_memory_count = live;
    }
    if (engine->dirty_memory_count == engine->dirty_memory_capacity) {
        engine->dirty_memory_capacity = engine->dirty_memory_capacity == 0 ? 128 :
                                        engine->dirty_memory_capacity * 2;
        engine->dirty_memories = xrealloc(engine->dirty_memories,
            engine->dirty_memory_capacity * sizeof(*engine->dirty_memories));
    }
    engine->dirty_memories[engine->dirty_memory_count++] = memory;
    memory->access_dirty = true;
}

static void ensure_memory_capacity(Engine *engine)
{
    if (engine->memory_count == engine->memory_capacity) {
        engine->memory_capacity = engine->memory_capacity == 0 ? 256 :
                                  engine->memory_capacity * 2;
        engine->memory_order = xrealloc(engine->memory_order,
            engine->memory_capacity * sizeof(*engine->memory_order));
        engine->memory_id_order = xrealloc(engine->memory_id_order,
            engine->memory_capacity * sizeof(*engine->memory_id_order));
        engine->memory_updated_order = xrealloc(engine->memory_updated_order,
            engine->memory_capacity * sizeof(*engine->memory_updated_order));
        engine->memory_source_memory_order = xrealloc(
            engine->memory_source_memory_order,
            engine->memory_capacity * sizeof(*engine->memory_source_memory_order));
        engine->memory_source_event_order = xrealloc(
            engine->memory_source_event_order,
            engine->memory_capacity * sizeof(*engine->memory_source_event_order));
        engine->query_touched = xrealloc(engine->query_touched,
            engine->memory_capacity * sizeof(*engine->query_touched));
        engine->query_touched_capacity = engine->memory_capacity;
    }
}

static void append_posting(Token *token, Memory *memory, uint64_t occurrences)
{
    if (token->posting_count == token->posting_capacity) {
        token->posting_capacity = token->posting_capacity == 0 ? 4 :
                                  token->posting_capacity * 2;
        token->postings = xrealloc(token->postings,
            token->posting_capacity * sizeof(*token->postings));
    }
    token->postings[token->posting_count].memory = memory;
    token->postings[token->posting_count].occurrences = occurrences;
    ++token->posting_count;
}

static int compare_u64(const void *left, const void *right)
{
    uint64_t a = *(const uint64_t *)left;
    uint64_t b = *(const uint64_t *)right;
    return a < b ? -1 : a > b ? 1 : 0;
}

static bool posting_expansion_within_budget(const Engine *engine,
                                            const uint64_t *tokens, size_t count)
{
    size_t expanded = 0;
    for (size_t i = 0; i < count; ++i) {
        size_t segment_size = engine->tokens[tokens[i]].segment_size;
        size_t nodes;
        if (segment_size == 0 || segment_size > (SIZE_MAX / 2) + 1) return false;
        nodes = segment_size * 2 - 1;
        if (nodes > MAX_EXPANDED_POSTING_NODES - expanded) return false;
        expanded += nodes;
    }
    return true;
}

static int index_postings(Engine *engine, Memory *memory)
{
    TokenVector stack = {0};
    TokenVector expanded = {0};
    size_t position = 0;
    if (memory->token_count == 0) return 0;
    if (!posting_expansion_within_budget(engine, memory->tokens,
                                         memory->token_count)) return -1;
    for (size_t i = 0; i < memory->token_count; ++i) {
        token_vector_push(&stack, memory->tokens[i]);
        while (stack.count != 0) {
            uint64_t token_id = stack.items[--stack.count];
            Token *token = &engine->tokens[token_id];
            if (expanded.count >= MAX_EXPANDED_POSTING_NODES) goto invalid;
            token_vector_push(&expanded, token_id);
            if (token->has_children) {
                if (stack.count > MAX_EXPANDED_POSTING_NODES - 2) goto invalid;
                token_vector_push(&stack, token->right);
                token_vector_push(&stack, token->left);
            }
        }
    }
    if (expanded.count > 1)
        qsort(expanded.items, expanded.count, sizeof(*expanded.items), compare_u64);
    while (position < expanded.count) {
        size_t end = position + 1;
        while (end < expanded.count && expanded.items[end] == expanded.items[position]) ++end;
        append_posting(&engine->tokens[expanded.items[position]], memory,
                       (uint64_t)(end - position));
        position = end;
    }
    free(stack.items);
    free(expanded.items);
    return 0;
invalid:
    free(stack.items);
    free(expanded.items);
    return -1;
}

static void add_link_evidence(Engine *engine, uint64_t source, uint64_t target,
                              uint64_t evidence)
{
    Token *token;
    uint32_t *slot;
    if (source == target) return;
    token = &engine->tokens[source];
    slot = link_index_slot(token, target, true);
    if (slot == NULL) {
        engine->poisoned = true;
        return;
    }
    if (*slot != 0) {
        Link *link = &token->links[*slot - 1];
        uint64_t current = link->evidence_and_dirty & LINK_EVIDENCE_MASK;
        uint64_t next = current + evidence;
        if (next > LINK_EVIDENCE_MASK) next = LINK_EVIDENCE_MASK;
        link->evidence_and_dirty =
            (link->evidence_and_dirty & ~LINK_EVIDENCE_MASK) | (uint32_t)next;
        enqueue_order_link(engine, source, link);
        return;
    }
    if (token->link_count == token->link_capacity) {
        token->link_capacity = token->link_capacity == 0 ? 8 : token->link_capacity * 2;
        token->links = xrealloc(token->links,
                                token->link_capacity * sizeof(*token->links));
    }
    if (token->link_count >= UINT32_MAX) {
        fputs("one token has too many outgoing links\n", stderr);
        engine->poisoned = true;
        return;
    }
    *slot = (uint32_t)token->link_count + 1;
    token->links[token->link_count].target = target;
    token->links[token->link_count].evidence_and_dirty =
        evidence > LINK_EVIDENCE_MASK ? LINK_EVIDENCE_MASK : (uint32_t)evidence;
    token->links[token->link_count].reinforcement = 0;
    ++token->link_count;
    ++engine->association_count;
    enqueue_order_link(engine, source, &token->links[token->link_count - 1]);
}

static void index_associations(Engine *engine, const uint64_t *tokens, size_t count)
{
    for (size_t i = 0; i < count; ++i) {
        size_t end = count < i + ASSOCIATION_WINDOW + 1 ?
                     count : i + ASSOCIATION_WINDOW + 1;
        for (size_t j = i + 1; j < end; ++j) {
            uint64_t evidence = ASSOCIATION_WINDOW + 1 - (j - i);
            add_link_evidence(engine, tokens[i], tokens[j], evidence);
            add_link_evidence(engine, tokens[j], tokens[i], evidence);
        }
    }
}

static int memory_updated_compare(const Memory *left, const Memory *right)
{
    size_t common = left->metadata.updated_at.size < right->metadata.updated_at.size ?
                    left->metadata.updated_at.size : right->metadata.updated_at.size;
    int compared = memcmp(left->metadata.updated_at.data,
                          right->metadata.updated_at.data, common);
    if (compared == 0 && left->metadata.updated_at.size != right->metadata.updated_at.size)
        compared = left->metadata.updated_at.size < right->metadata.updated_at.size ? -1 : 1;
    if (compared == 0)
        compared = left->metadata.id < right->metadata.id ? -1 :
                   left->metadata.id > right->metadata.id ? 1 : 0;
    return compared;
}

static MemoryFilterIndex *memory_filter_find(Engine *engine, const char *tier,
                                              const char *status, bool create)
{
    for (size_t i = 0; i < engine->memory_filter_index_count; ++i) {
        MemoryFilterIndex *index = &engine->memory_filter_indexes[i];
        if (strcmp(index->tier, tier) == 0 && strcmp(index->status, status) == 0)
            return index;
    }
    if (!create) return NULL;
    if (engine->memory_filter_index_count == engine->memory_filter_index_capacity) {
        engine->memory_filter_index_capacity =
            engine->memory_filter_index_capacity == 0 ? 16 :
            engine->memory_filter_index_capacity * 2;
        engine->memory_filter_indexes = xrealloc(engine->memory_filter_indexes,
            engine->memory_filter_index_capacity *
                sizeof(*engine->memory_filter_indexes));
    }
    {
        MemoryFilterIndex *index =
            &engine->memory_filter_indexes[engine->memory_filter_index_count++];
        memset(index, 0, sizeof(*index));
        index->tier = xstrdup(tier);
        index->status = xstrdup(status);
        return index;
    }
}

static void memory_filter_insert(Engine *engine, Memory *memory)
{
    MemoryFilterIndex *index = memory_filter_find(
        engine, memory->tier_name, (const char *)memory->metadata.status.data, true);
    size_t id_position = 0;
    size_t updated_position = 0;
    if (index->count == index->capacity) {
        index->capacity = index->capacity == 0 ? 64 : index->capacity * 2;
        index->id_order = xrealloc(index->id_order,
            index->capacity * sizeof(*index->id_order));
        index->updated_order = xrealloc(index->updated_order,
            index->capacity * sizeof(*index->updated_order));
    }
    while (id_position < index->count &&
           index->id_order[id_position]->metadata.id < memory->metadata.id)
        ++id_position;
    memmove(index->id_order + id_position + 1,
            index->id_order + id_position,
            (index->count - id_position) * sizeof(*index->id_order));
    index->id_order[id_position] = memory;
    while (updated_position < index->count &&
           memory_updated_compare(index->updated_order[updated_position],
                                  memory) < 0)
        ++updated_position;
    memmove(index->updated_order + updated_position + 1,
            index->updated_order + updated_position,
            (index->count - updated_position) * sizeof(*index->updated_order));
    index->updated_order[updated_position] = memory;
    ++index->count;
}

static int memory_filter_remove(Engine *engine, Memory *memory)
{
    MemoryFilterIndex *index = memory_filter_find(
        engine, memory->tier_name, (const char *)memory->metadata.status.data, false);
    size_t id_position = 0;
    size_t updated_position = 0;
    if (index == NULL) return -1;
    while (id_position < index->count && index->id_order[id_position] != memory)
        ++id_position;
    while (updated_position < index->count &&
           index->updated_order[updated_position] != memory)
        ++updated_position;
    if (id_position == index->count || updated_position == index->count) return -1;
    memmove(index->id_order + id_position,
            index->id_order + id_position + 1,
            (index->count - id_position - 1) * sizeof(*index->id_order));
    memmove(index->updated_order + updated_position,
            index->updated_order + updated_position + 1,
            (index->count - updated_position - 1) * sizeof(*index->updated_order));
    --index->count;
    return 0;
}

static int64_t memory_source_value(const Memory *memory, bool by_memory)
{
    return by_memory ? memory->metadata.source_memory_id :
                       memory->metadata.source_event_id;
}

static bool memory_has_source(const Memory *memory, bool by_memory)
{
    return by_memory ? memory->metadata.has_source_memory_id :
                       memory->metadata.has_source_event_id;
}

static void memory_source_insert(Engine *engine, Memory *memory, bool by_memory)
{
    Memory ***order_address = by_memory ? &engine->memory_source_memory_order :
                                         &engine->memory_source_event_order;
    size_t *count_address = by_memory ? &engine->memory_source_memory_count :
                                       &engine->memory_source_event_count;
    Memory **order = *order_address;
    size_t low = 0;
    size_t high = *count_address;
    int64_t value;
    if (!memory_has_source(memory, by_memory)) return;
    value = memory_source_value(memory, by_memory);
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        int64_t found = memory_source_value(order[middle], by_memory);
        if (found < value ||
            (found == value && order[middle]->metadata.id < memory->metadata.id))
            low = middle + 1;
        else
            high = middle;
    }
    memmove(order + low + 1, order + low,
            (*count_address - low) * sizeof(*order));
    order[low] = memory;
    ++*count_address;
}

static int memory_source_remove(Engine *engine, Memory *memory, bool by_memory)
{
    Memory **order = by_memory ? engine->memory_source_memory_order :
                                 engine->memory_source_event_order;
    size_t *count_address = by_memory ? &engine->memory_source_memory_count :
                                       &engine->memory_source_event_count;
    size_t position = 0;
    if (!memory_has_source(memory, by_memory)) return 0;
    while (position < *count_address && order[position] != memory) ++position;
    if (position == *count_address) return -1;
    memmove(order + position, order + position + 1,
            (*count_address - position - 1) * sizeof(*order));
    --*count_address;
    return 0;
}

static void memory_index_insert(Engine *engine, Memory *memory)
{
    size_t low = 0;
    size_t high = engine->memory_count;
    size_t updated_low = 0;
    size_t updated_high = engine->memory_count;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        if (engine->memory_id_order[middle]->metadata.id < memory->metadata.id)
            low = middle + 1;
        else
            high = middle;
    }
    memmove(engine->memory_id_order + low + 1,
            engine->memory_id_order + low,
            (engine->memory_count - low) * sizeof(*engine->memory_id_order));
    engine->memory_id_order[low] = memory;
    while (updated_low < updated_high) {
        size_t middle = updated_low + (updated_high - updated_low) / 2;
        if (memory_updated_compare(engine->memory_updated_order[middle], memory) < 0)
            updated_low = middle + 1;
        else
            updated_high = middle;
    }
    memmove(engine->memory_updated_order + updated_low + 1,
            engine->memory_updated_order + updated_low,
            (engine->memory_count - updated_low) *
                sizeof(*engine->memory_updated_order));
    engine->memory_updated_order[updated_low] = memory;
    if (memory->visible) {
        memory_filter_insert(engine, memory);
        memory_source_insert(engine, memory, true);
        memory_source_insert(engine, memory, false);
    }
}

static int memory_reindex_updated(Engine *engine, Memory *memory)
{
    size_t position = 0;
    size_t count = engine->memory_count;
    size_t low = 0;
    size_t high;
    while (position < count && engine->memory_updated_order[position] != memory)
        ++position;
    if (position == count) return -1;
    memmove(engine->memory_updated_order + position,
            engine->memory_updated_order + position + 1,
            (count - position - 1) * sizeof(*engine->memory_updated_order));
    high = count - 1;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        if (memory_updated_compare(engine->memory_updated_order[middle], memory) < 0)
            low = middle + 1;
        else
            high = middle;
    }
    memmove(engine->memory_updated_order + low + 1,
            engine->memory_updated_order + low,
            (count - 1 - low) * sizeof(*engine->memory_updated_order));
    engine->memory_updated_order[low] = memory;
    return 0;
}

static Memory *memory_find_id(Engine *engine, int64_t id);

static Memory *memory_find(Engine *engine, const char *tier, const char *key)
{
    int64_t id;
    Memory *memory;
    if (parse_memory_id(key, &id) != 0) return NULL;
    memory = memory_find_id(engine, id);
    return memory != NULL && strcmp(memory->tier_name, tier) == 0 ? memory : NULL;
}

static Memory *memory_find_id(Engine *engine, int64_t id)
{
    size_t low = 0;
    size_t high = engine->memory_count;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        int64_t found = engine->memory_id_order[middle]->metadata.id;
        if (found < id) low = middle + 1;
        else high = middle;
    }
    return low < engine->memory_count &&
           engine->memory_id_order[low]->metadata.id == id ?
           engine->memory_id_order[low] : NULL;
}

static void memory_free(Memory *memory);

static int memory_publish_resident(Engine *engine, Metadata *metadata,
                                   TokenVector *content, TokenVector *metadata_tokens,
                                   const unsigned char content_digest[32],
                                   const unsigned char metadata_digest[32],
                                   bool visible)
{
    Memory *memory = xcalloc(1, sizeof(*memory));
    Sha256 content_bytes_sha;
    char key[64];
    snprintf(key, sizeof(key), "%" PRId64, metadata->id);
    memory->metadata = *metadata;
    memset(metadata, 0, sizeof(*metadata));
    memory->key = xstrdup(key);
    memory->tier_name = xstrdup((const char *)memory->metadata.tier.data);
    memory->tokens = content->items;
    memory->token_count = content->count;
    memset(content, 0, sizeof(*content));
    memory->metadata_tokens = metadata_tokens->items;
    memory->metadata_token_count = metadata_tokens->count;
    memset(metadata_tokens, 0, sizeof(*metadata_tokens));
    memcpy(memory->content_digest, content_digest, 32);
    memcpy(memory->metadata_digest, metadata_digest, 32);
    /* Blob identity depends on historical tokenization. Content identity does
     * not; hash registry segments directly without materializing the prose. */
    sha256_init(&content_bytes_sha);
    for (size_t i = 0; i < memory->token_count; ++i) {
        uint64_t id = memory->tokens[i];
        const Token *token;
        if (id >= engine->token_count ||
            engine->tokens[id].segment_size >
                MAX_MEMORY_BLOB_BYTES - memory->content_byte_count) {
            memory_free(memory);
            return -1;
        }
        token = &engine->tokens[id];
        memory->content_byte_count += token->segment_size;
        sha256_update(&content_bytes_sha, token->segment, token->segment_size);
    }
    sha256_final(&content_bytes_sha, memory->content_bytes_digest);
    memory->insertion_index = engine->memory_count;
    memory->visible = visible;
    if (index_postings(engine, memory) != 0) {
        memory_free(memory);
        return -1;
    }
    ensure_memory_capacity(engine);
    memory_index_insert(engine, memory);
    engine->memory_order[engine->memory_count++] = memory;
    index_associations(engine, memory->tokens, memory->token_count);
    return engine->poisoned ? -1 : 0;
}

static uint64_t link_strength(const Link *link)
{
    return (uint64_t)(link->evidence_and_dirty & LINK_EVIDENCE_MASK) +
           link->reinforcement;
}

static bool link_after(const Link *left, const Link *right)
{
    uint64_t a = link_strength(left);
    uint64_t b = link_strength(right);
    return b > a || (b == a && right->target < left->target);
}

static bool posting_after(const Posting *left, const Posting *right)
{
    if (right->memory->access_count != left->memory->access_count)
        return right->memory->access_count > left->memory->access_count;
    if (right->occurrences != left->occurrences)
        return right->occurrences > left->occurrences;
    return right->memory->insertion_index < left->memory->insertion_index;
}

static bool token_after(const Token *left, uint64_t left_id,
                        const Token *right, uint64_t right_id)
{
    if (right->usage_count != left->usage_count)
        return right->usage_count > left->usage_count;
    if (right->read_count != left->read_count)
        return right->read_count > left->read_count;
    if (right->write_count != left->write_count)
        return right->write_count > left->write_count;
    return right_id < left_id;
}

static bool token_id_hotter(const Engine *engine, uint64_t left, uint64_t right)
{
    const Token *a = &engine->tokens[left];
    const Token *b = &engine->tokens[right];
    if (a->order_usage_count != b->order_usage_count)
        return a->order_usage_count > b->order_usage_count;
    if (a->order_read_count != b->order_read_count)
        return a->order_read_count > b->order_read_count;
    if (a->order_write_count != b->order_write_count)
        return a->order_write_count > b->order_write_count;
    return left < right;
}

static void swap_token_heap_positions(Engine *engine, size_t left, size_t right)
{
    uint64_t temporary = engine->token_order[left];
    engine->token_order[left] = engine->token_order[right];
    engine->token_order[right] = temporary;
    engine->tokens[engine->token_order[left]].order_position = left;
    engine->tokens[engine->token_order[right]].order_position = right;
}

static void enqueue_order_token(Engine *engine, uint64_t token_id)
{
    Token *token;
    if (!engine->ordering_ready || !engine->order_enqueue_enabled ||
        token_id >= engine->token_count) return;
    token = &engine->tokens[token_id];
    if (token->order_dirty) return;
    if (engine->order_token_count == engine->order_token_capacity &&
        engine->order_token_head != 0) {
        size_t live = engine->order_token_count - engine->order_token_head;
        memmove(engine->order_tokens,
                engine->order_tokens + engine->order_token_head,
                live * sizeof(*engine->order_tokens));
        engine->order_token_head = 0;
        engine->order_token_count = live;
    }
    if (engine->order_token_count == engine->order_token_capacity) {
        engine->order_token_capacity = engine->order_token_capacity == 0 ? 256 :
                                       engine->order_token_capacity * 2;
        engine->order_tokens = xrealloc(engine->order_tokens,
            engine->order_token_capacity * sizeof(*engine->order_tokens));
    }
    engine->order_tokens[engine->order_token_count++] = token_id;
    token->order_dirty = true;
}

static void prioritize_order_token(Engine *engine, uint64_t token_id)
{
    Token *token;
    size_t position;
    if (token_id >= engine->token_count) {
        engine->poisoned = true;
        return;
    }
    token = &engine->tokens[token_id];
    position = token->order_position;
    if (!token->order_dirty || position >= engine->token_order_count ||
        engine->token_order[position] != token_id) {
        engine->poisoned = true;
        return;
    }
    token->order_write_count = token->write_count;
    token->order_read_count = token->read_count;
    token->order_usage_count = token->usage_count;
    while (position != 0) {
        size_t parent = (position - 1) / 2;
        if (!token_id_hotter(engine, engine->token_order[position],
                             engine->token_order[parent])) break;
        swap_token_heap_positions(engine, position, parent);
        position = parent;
    }
    for (;;) {
        size_t left = position * 2 + 1;
        size_t right = left + 1;
        size_t hottest = position;
        if (left < engine->token_order_count &&
            token_id_hotter(engine, engine->token_order[left],
                             engine->token_order[hottest])) hottest = left;
        if (right < engine->token_order_count &&
            token_id_hotter(engine, engine->token_order[right],
                             engine->token_order[hottest])) hottest = right;
        if (hottest == position) break;
        swap_token_heap_positions(engine, position, hottest);
        position = hottest;
    }
    token->order_dirty = false;
}

static void prioritize_order_tokens(Engine *engine, size_t budget)
{
    size_t available = engine->order_token_count - engine->order_token_head;
    size_t batch = available < budget ? available : budget;
    for (size_t i = 0; i < batch && !engine->poisoned; ++i) {
        prioritize_order_token(engine,
            engine->order_tokens[engine->order_token_head++]);
    }
    if (engine->order_token_head == engine->order_token_count) {
        engine->order_token_head = 0;
        engine->order_token_count = 0;
    }
}

static bool swap_link_positions(Engine *engine, Token *token,
                                size_t left_position, size_t right_position)
{
    Link temporary;
    uint32_t *first;
    uint32_t *second;
    if (left_position >= token->link_count || right_position >= token->link_count ||
        left_position == right_position || left_position >= UINT32_MAX ||
        right_position >= UINT32_MAX) {
        engine->poisoned = true;
        return false;
    }
    first = link_index_slot(token, token->links[left_position].target, false);
    second = link_index_slot(token, token->links[right_position].target, false);
    if (first == NULL || second == NULL || first == second ||
        *first != left_position + 1 || *second != right_position + 1) {
        fputs("resident link index invariant failed\n", stderr);
        engine->poisoned = true;
        return false;
    }
    temporary = token->links[left_position];
    token->links[left_position] = token->links[right_position];
    token->links[right_position] = temporary;
    *first = (uint32_t)right_position + 1;
    *second = (uint32_t)left_position + 1;
    return true;
}

static void prioritize_order_link(Engine *engine, EdgeKey key)
{
    Token *token;
    uint32_t *slot;
    size_t position;
    size_t window;
    if (key.source >= engine->token_count) {
        engine->poisoned = true;
        return;
    }
    token = &engine->tokens[key.source];
    slot = link_index_slot(token, key.target, false);
    if (slot == NULL || *slot == 0) {
        engine->poisoned = true;
        return;
    }
    position = *slot - 1;
    if ((token->links[position].evidence_and_dirty & LINK_ORDER_DIRTY) == 0) {
        engine->poisoned = true;
        return;
    }
    window = token->link_count < 16 ? token->link_count : 16;
    if (position >= window && window != 0) {
        size_t weakest = 0;
        for (size_t i = 1; i < window; ++i) {
            if (link_after(&token->links[i], &token->links[weakest])) weakest = i;
        }
        if (link_after(&token->links[weakest], &token->links[position])) {
            if (!swap_link_positions(engine, token, weakest, position)) return;
        }
    }
    for (size_t end = window; end > 1; --end) {
        bool swapped = false;
        for (size_t i = 1; i < end; ++i) {
            if (link_after(&token->links[i - 1], &token->links[i])) {
                if (!swap_link_positions(engine, token, i - 1, i)) return;
                swapped = true;
            }
        }
        if (!swapped) break;
    }
    slot = link_index_slot(token, key.target, false);
    if (slot == NULL || *slot == 0) {
        engine->poisoned = true;
        return;
    }
    token->links[*slot - 1].evidence_and_dirty &= ~LINK_ORDER_DIRTY;
}

static void prioritize_order_links(Engine *engine, size_t budget)
{
    size_t available = engine->order_link_count - engine->order_link_head;
    size_t batch = available < budget ? available : budget;
    for (size_t i = 0; i < batch && !engine->poisoned; ++i) {
        prioritize_order_link(engine,
            engine->order_links[engine->order_link_head++]);
    }
    if (engine->order_link_head == engine->order_link_count) {
        engine->order_link_head = 0;
        engine->order_link_count = 0;
    }
}

static int flush_dirty_locked(Engine *engine, size_t budget)
{
    sqlite3_stmt *statement = NULL;
    size_t available = engine->dirty_count - engine->dirty_head;
    size_t batch = available < budget ? available : budget;
    int rc = -1;
    bool transaction = false;
    if (batch == 0) return 0;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (sqlite3_prepare_v2(engine->db,
            "UPDATE token_translation SET write_count=?1,read_count=?2,usage_count=?3 "
            "WHERE token_id=?4", -1, &statement, NULL) != SQLITE_OK) goto rollback;
    for (size_t i = 0; i < batch; ++i) {
        uint64_t id = engine->dirty_tokens[engine->dirty_head + i];
        Token *token = &engine->tokens[id];
        unsigned char writes[8], reads[8], usage[8];
        put_u64_le(writes, token->write_count);
        put_u64_le(reads, token->read_count);
        put_u64_le(usage, token->usage_count);
        if (sqlite3_bind_blob(statement, 1, writes, 8, SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_bind_blob(statement, 2, reads, 8, SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_bind_blob(statement, 3, usage, 8, SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_bind_int64(statement, 4, (sqlite3_int64)id) != SQLITE_OK ||
            sqlite3_step(statement) != SQLITE_DONE || sqlite3_changes(engine->db) != 1) {
            fprintf(stderr, "flush token counters: %s\n", sqlite3_errmsg(engine->db));
            goto rollback;
        }
        sqlite3_reset(statement);
        sqlite3_clear_bindings(statement);
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite_exec_checked(engine->db, "COMMIT") != 0) goto rollback;
    transaction = false;
    for (size_t i = 0; i < batch; ++i) {
        engine->tokens[engine->dirty_tokens[engine->dirty_head + i]].counters_dirty = false;
    }
    engine->dirty_head += batch;
    if (engine->dirty_head == engine->dirty_count) {
        engine->dirty_head = 0;
        engine->dirty_count = 0;
    }
    rc = 0;
    goto done;
rollback:
    if (transaction) sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int flush_dirty_pairs_locked(Engine *engine, size_t budget)
{
    sqlite3_stmt *statement = NULL;
    size_t available = engine->dirty_pair_count - engine->dirty_pair_head;
    size_t batch = available < budget ? available : budget;
    int rc = -1;
    bool transaction = false;
    if (batch == 0) return 0;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (sqlite3_prepare_v2(engine->db,
            "INSERT INTO pair_observation(left_id,right_id,observations) VALUES(?1,?2,?3) "
            "ON CONFLICT(left_id,right_id) DO UPDATE SET observations=excluded.observations",
            -1, &statement, NULL) != SQLITE_OK) goto rollback;
    for (size_t i = 0; i < batch; ++i) {
        PairKey key = engine->dirty_pairs[engine->dirty_pair_head + i];
        PairSlot *pair = pair_map_find(&engine->pairs, key.left, key.right);
        unsigned char observations[8];
        if (pair == NULL || !pair->dirty) goto rollback;
        put_u64_le(observations, pair->observations);
        if (sqlite3_bind_int64(statement, 1, (sqlite3_int64)key.left) != SQLITE_OK ||
            sqlite3_bind_int64(statement, 2, (sqlite3_int64)key.right) != SQLITE_OK ||
            sqlite3_bind_blob(statement, 3, observations, 8,
                              SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_step(statement) != SQLITE_DONE) goto rollback;
        sqlite3_reset(statement);
        sqlite3_clear_bindings(statement);
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite_exec_checked(engine->db, "COMMIT") != 0) goto rollback;
    transaction = false;
    for (size_t i = 0; i < batch; ++i) {
        PairKey key = engine->dirty_pairs[engine->dirty_pair_head + i];
        PairSlot *pair = pair_map_find(&engine->pairs, key.left, key.right);
        if (pair != NULL) pair->dirty = false;
    }
    engine->dirty_pair_head += batch;
    if (engine->dirty_pair_head == engine->dirty_pair_count) {
        engine->dirty_pair_head = 0;
        engine->dirty_pair_count = 0;
    }
    rc = 0;
    goto done;
rollback:
    if (transaction) sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int flush_dirty_links_locked(Engine *engine, size_t budget)
{
    sqlite3_stmt *statement = NULL;
    size_t available = engine->dirty_link_count - engine->dirty_link_head;
    size_t batch = available < budget ? available : budget;
    int rc = -1;
    bool transaction = false;
    if (batch == 0) return 0;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (sqlite3_prepare_v2(engine->db,
            "INSERT INTO link_reinforcement(source_id,target_id,reinforcement) "
            "VALUES(?1,?2,?3) ON CONFLICT(source_id,target_id) "
            "DO UPDATE SET reinforcement=excluded.reinforcement",
            -1, &statement, NULL) != SQLITE_OK) goto rollback;
    for (size_t i = 0; i < batch; ++i) {
        EdgeKey key = engine->dirty_links[engine->dirty_link_head + i];
        Token *token;
        uint32_t *slot;
        Link *link;
        if (key.source >= engine->token_count) goto rollback;
        token = &engine->tokens[key.source];
        slot = link_index_slot(token, key.target, false);
        if (slot == NULL || *slot == 0) goto rollback;
        link = &token->links[*slot - 1];
        if ((link->evidence_and_dirty & LINK_PERSIST_DIRTY) == 0) goto rollback;
        if (sqlite3_bind_int64(statement, 1,
                               (sqlite3_int64)key.source) != SQLITE_OK ||
            sqlite3_bind_int64(statement, 2,
                               (sqlite3_int64)key.target) != SQLITE_OK ||
            sqlite3_bind_int64(statement, 3, link->reinforcement) != SQLITE_OK ||
            sqlite3_step(statement) != SQLITE_DONE) goto rollback;
        sqlite3_reset(statement);
        sqlite3_clear_bindings(statement);
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite_exec_checked(engine->db, "COMMIT") != 0) goto rollback;
    transaction = false;
    for (size_t i = 0; i < batch; ++i) {
        EdgeKey key = engine->dirty_links[engine->dirty_link_head + i];
        uint32_t *slot = link_index_slot(&engine->tokens[key.source], key.target, false);
        if (slot != NULL && *slot != 0)
            engine->tokens[key.source].links[*slot - 1].evidence_and_dirty &=
                ~LINK_PERSIST_DIRTY;
    }
    engine->dirty_link_head += batch;
    if (engine->dirty_link_head == engine->dirty_link_count) {
        engine->dirty_link_head = 0;
        engine->dirty_link_count = 0;
    }
    rc = 0;
    goto done;
rollback:
    if (transaction) sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int flush_dirty_memories_locked(Engine *engine, size_t budget)
{
    sqlite3_stmt *statement = NULL;
    size_t available = engine->dirty_memory_count - engine->dirty_memory_head;
    size_t batch = available < budget ? available : budget;
    int rc = -1;
    bool transaction = false;
    if (batch == 0) return 0;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (sqlite3_prepare_v2(engine->db,
            "INSERT INTO memory_state(memory_id,access_count) VALUES(?1,?2) "
            "ON CONFLICT(memory_id) DO UPDATE SET access_count=excluded.access_count",
            -1, &statement, NULL) != SQLITE_OK) goto rollback;
    for (size_t i = 0; i < batch; ++i) {
        Memory *memory = engine->dirty_memories[engine->dirty_memory_head + i];
        unsigned char access[8];
        if (!memory->access_dirty) goto rollback;
        put_u64_le(access, memory->access_count);
        if (sqlite3_bind_int64(statement, 1,
                               memory->metadata.id) != SQLITE_OK ||
            sqlite3_bind_blob(statement, 2, access, 8,
                              SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_step(statement) != SQLITE_DONE) goto rollback;
        sqlite3_reset(statement);
        sqlite3_clear_bindings(statement);
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite_exec_checked(engine->db, "COMMIT") != 0) goto rollback;
    transaction = false;
    for (size_t i = 0; i < batch; ++i)
        engine->dirty_memories[engine->dirty_memory_head + i]->access_dirty = false;
    engine->dirty_memory_head += batch;
    if (engine->dirty_memory_head == engine->dirty_memory_count) {
        engine->dirty_memory_head = 0;
        engine->dirty_memory_count = 0;
    }
    rc = 0;
    goto done;
rollback:
    if (transaction) sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int flush_dirty_state_locked(Engine *engine, size_t budget)
{
    return flush_dirty_locked(engine, budget) == 0 &&
           flush_dirty_pairs_locked(engine, budget) == 0 &&
           flush_dirty_links_locked(engine, budget) == 0 &&
           flush_dirty_memories_locked(engine, budget) == 0 ? 0 : -1;
}

static bool has_dirty_state(const Engine *engine)
{
    return engine->dirty_head < engine->dirty_count ||
           engine->dirty_pair_head < engine->dirty_pair_count ||
           engine->dirty_link_head < engine->dirty_link_count ||
           engine->dirty_memory_head < engine->dirty_memory_count;
}

static unsigned rank_interval_from_environment(void)
{
    const char *text = getenv("TOKMEM_RANK_INTERVAL_MS");
    char *end = NULL;
    unsigned long value;
    if (text == NULL || *text == '\0') return DEFAULT_RANK_INTERVAL_MS;
    errno = 0;
    value = strtoul(text, &end, 10);
    if (errno != 0 || *end != '\0' || value < MIN_RANK_INTERVAL_MS ||
        value > MAX_RANK_INTERVAL_MS) {
        fprintf(stderr, "ignoring invalid TOKMEM_RANK_INTERVAL_MS=%s\n", text);
        return DEFAULT_RANK_INTERVAL_MS;
    }
    return (unsigned)value;
}

static void *ranker_main(void *opaque)
{
    Engine *engine = opaque;
    sigset_t blocked;
    sigemptyset(&blocked);
    sigaddset(&blocked, SIGINT);
    sigaddset(&blocked, SIGTERM);
    pthread_sigmask(SIG_BLOCK, &blocked, NULL);
    pthread_mutex_lock(&engine->mutex);
    while (!atomic_load(&engine->stop_ranker)) {
        struct timespec deadline;
        clock_gettime(CLOCK_REALTIME, &deadline);
        deadline.tv_nsec += (long)engine->rank_interval_ms * 1000000L;
        deadline.tv_sec += deadline.tv_nsec / 1000000000L;
        deadline.tv_nsec %= 1000000000L;
        pthread_cond_timedwait(&engine->condition, &engine->mutex, &deadline);
        if (atomic_load(&engine->stop_ranker)) break;
        /* Hot token heap and changed link windows converge independently. */
        prioritize_order_tokens(engine, 64);
        prioritize_order_links(engine, 64);
        if (flush_dirty_state_locked(engine, 256) != 0) {
            fputs("background learned-state flush failed; retaining dirty state\n", stderr);
        }
    }
    pthread_mutex_unlock(&engine->mutex);
    return NULL;
}

static int start_ranker(Engine *engine)
{
    engine->rank_interval_ms = rank_interval_from_environment();
    atomic_init(&engine->stop_ranker, false);
    if (pthread_create(&engine->rank_thread, NULL, ranker_main, engine) != 0) {
        fputs("start background ranker: failed\n", stderr);
        return -1;
    }
    engine->ranker_started = true;
    return 0;
}

static int load_record(Engine *engine, const char *tier, const char *key)
{
    unsigned char *content_blob = NULL;
    unsigned char *metadata_blob = NULL;
    unsigned char *metadata_text = NULL;
    size_t content_size = 0;
    size_t metadata_size = 0;
    size_t metadata_text_size = 0;
    TokenVector content_tokens = {0};
    TokenVector metadata_tokens = {0};
    Metadata metadata = {0};
    char expected_key[64];
    int rc = -1;

    if (read_record_blobs(engine, tier, key, &content_blob, &content_size,
                          &metadata_blob, &metadata_size) != 0 ||
        decode_memory_blob(engine, content_blob, content_size, &content_tokens) != 0 ||
        decode_memory_blob(engine, metadata_blob, metadata_size, &metadata_tokens) != 0 ||
        decode_tokens_to_bytes(engine, &metadata_tokens,
                               &metadata_text, &metadata_text_size) != 0 ||
        metadata_decode(metadata_text, metadata_text_size, &metadata, false) != 0) {
        fprintf(stderr, "invalid memory record %s/%s\n", tier, key);
        goto done;
    }
    snprintf(expected_key, sizeof(expected_key), "%" PRId64, metadata.id);
    if (strcmp(key, expected_key) != 0 ||
        metadata.tier.size != strlen(tier) ||
        memcmp(metadata.tier.data, tier, metadata.tier.size) != 0 ||
        memory_find(engine, tier, key) != NULL || memory_find_id(engine, metadata.id) != NULL) {
        fprintf(stderr, "metadata/path mismatch for %s/%s\n", tier, key);
        goto done;
    }
    {
        unsigned char content_digest[32];
        unsigned char metadata_digest[32];
        sha256_bytes(content_blob, content_size, content_digest);
        sha256_bytes(metadata_blob, metadata_size, metadata_digest);
        if (memory_publish_resident(engine, &metadata, &content_tokens,
                                    &metadata_tokens, content_digest,
                                    metadata_digest, true) != 0) {
            fprintf(stderr, "posting expansion exceeds the resident budget for %s/%s\n",
                    tier, key);
            goto done;
        }
    }
    rc = 0;
done:
    free(content_blob);
    free(metadata_blob);
    free(metadata_text);
    free(content_tokens.items);
    free(metadata_tokens.items);
    metadata_free(&metadata);
    return rc;
}

static int compare_memory_ids(const void *left, const void *right)
{
    const Memory *a = *(Memory * const *)left;
    const Memory *b = *(Memory * const *)right;
    return a->metadata.id < b->metadata.id ? -1 :
           a->metadata.id > b->metadata.id ? 1 : 0;
}

static int load_all_memories(Engine *engine)
{
    DIR *tiers = opendir(engine->memory_dir);
    struct dirent *tier_entry;
    if (tiers == NULL) {
        fprintf(stderr, "open memory directory: %s\n", strerror(errno));
        return -1;
    }
    while ((tier_entry = readdir(tiers)) != NULL) {
        char *tier_path;
        struct stat tier_stat;
        DIR *records;
        struct dirent *record_entry;
        if (tier_entry->d_name[0] == '.') continue;
        if (safe_name((const unsigned char *)tier_entry->d_name,
                      strlen(tier_entry->d_name)) != 0) {
            fprintf(stderr, "invalid tier directory name %s\n", tier_entry->d_name);
            closedir(tiers);
            return -1;
        }
        tier_path = path_join(engine->memory_dir, tier_entry->d_name);
        if (lstat(tier_path, &tier_stat) != 0 || !S_ISDIR(tier_stat.st_mode)) {
            fprintf(stderr, "tier %s is not a real directory\n", tier_entry->d_name);
            free(tier_path);
            closedir(tiers);
            return -1;
        }
        records = opendir(tier_path);
        if (records == NULL) {
            free(tier_path);
            closedir(tiers);
            return -1;
        }
        while ((record_entry = readdir(records)) != NULL) {
            char *record_path;
            struct stat record_stat;
            int64_t ignored_id;
            if (record_entry->d_name[0] == '.') continue;
            if (parse_memory_id(record_entry->d_name, &ignored_id) != 0) {
                fprintf(stderr, "invalid memory directory %s/%s\n",
                        tier_entry->d_name, record_entry->d_name);
                closedir(records);
                free(tier_path);
                closedir(tiers);
                return -1;
            }
            record_path = path_join(tier_path, record_entry->d_name);
            if (lstat(record_path, &record_stat) != 0 || !S_ISDIR(record_stat.st_mode) ||
                load_record(engine, tier_entry->d_name, record_entry->d_name) != 0) {
                free(record_path);
                closedir(records);
                free(tier_path);
                closedir(tiers);
                return -1;
            }
            free(record_path);
        }
        closedir(records);
        free(tier_path);
    }
    closedir(tiers);
    if (engine->memory_count > 1) {
        qsort(engine->memory_order, engine->memory_count,
              sizeof(*engine->memory_order), compare_memory_ids);
    }
    for (size_t i = 0; i < engine->memory_count; ++i) {
        engine->memory_order[i]->insertion_index = i;
    }
    return 0;
}

static int load_pair_state(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    int rc = sqlite3_prepare_v2(engine->db,
        "SELECT left_id,right_id,observations FROM pair_observation",
        -1, &statement, NULL);
    if (rc != SQLITE_OK) return -1;
    while ((rc = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 left = sqlite3_column_int64(statement, 0);
        sqlite3_int64 right = sqlite3_column_int64(statement, 1);
        const unsigned char *observations = sqlite3_column_blob(statement, 2);
        if (left < 0 || right < 0 || (uint64_t)left >= engine->token_count ||
            (uint64_t)right >= engine->token_count ||
            sqlite3_column_bytes(statement, 2) != 8) {
            sqlite3_finalize(statement);
            return -1;
        }
        pair_map_set(engine, (uint64_t)left, (uint64_t)right,
                     get_u64_le(observations), false);
    }
    sqlite3_finalize(statement);
    return rc == SQLITE_DONE ? 0 : -1;
}

static int load_reinforcement_state(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    int rc = sqlite3_prepare_v2(engine->db,
        "SELECT source_id,target_id,reinforcement FROM link_reinforcement",
        -1, &statement, NULL);
    if (rc != SQLITE_OK) return -1;
    while ((rc = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 source = sqlite3_column_int64(statement, 0);
        sqlite3_int64 target = sqlite3_column_int64(statement, 1);
        sqlite3_int64 reinforcement = sqlite3_column_int64(statement, 2);
        uint32_t *slot;
        if (source < 0 || target < 0 || reinforcement < 0 ||
            reinforcement > UINT32_MAX || (uint64_t)source >= engine->token_count ||
            (uint64_t)target >= engine->token_count) {
            sqlite3_finalize(statement);
            return -1;
        }
        slot = link_index_slot(&engine->tokens[source], (uint64_t)target, false);
        if (slot == NULL || *slot == 0) {
            sqlite3_finalize(statement);
            return -1;
        }
        engine->tokens[source].links[*slot - 1].reinforcement =
            (uint32_t)reinforcement;
    }
    sqlite3_finalize(statement);
    return rc == SQLITE_DONE ? 0 : -1;
}

static int load_memory_access_state(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    int rc = sqlite3_prepare_v2(engine->db,
        "SELECT memory_id,access_count FROM memory_state", -1, &statement, NULL);
    if (rc != SQLITE_OK) return -1;
    while ((rc = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 id = sqlite3_column_int64(statement, 0);
        Memory *memory = id > 0 ? memory_find_id(engine, (int64_t)id) : NULL;
        if (memory == NULL || sqlite3_column_bytes(statement, 1) != 8) {
            sqlite3_finalize(statement);
            return -1;
        }
        memory->access_count = get_u64_le(sqlite3_column_blob(statement, 1));
    }
    sqlite3_finalize(statement);
    return rc == SQLITE_DONE ? 0 : -1;
}

static int validate_operation_receipts(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    int step;
    if (sqlite3_prepare_v2(engine->db,
            "SELECT kind,memory_id,state FROM operation_receipt",
            -1, &statement, NULL) != SQLITE_OK) return -1;
    while ((step = sqlite3_step(statement)) == SQLITE_ROW) {
        const char *kind = (const char *)sqlite3_column_text(statement, 0);
        sqlite3_int64 id = sqlite3_column_int64(statement, 1);
        int state = sqlite3_column_int(statement, 2);
        Memory *memory = id > 0 ? memory_find_id(engine, id) : NULL;
        bool known_kind = kind != NULL &&
            (strcmp(kind, "create") == 0 || strcmp(kind, "update") == 0 ||
             strcmp(kind, "replace") == 0);
        if (id <= 0 || !known_kind || (state != 0 && state != 1) ||
            (state == 1 && memory == NULL) ||
            (state == 0 && strcmp(kind, "update") == 0 && memory == NULL)) {
            fputs("invalid or orphaned completed operation receipt\n", stderr);
            sqlite3_finalize(statement);
            return -1;
        }
        if (state == 0 && memory != NULL &&
            (strcmp(kind, "create") == 0 || strcmp(kind, "replace") == 0)) {
            if (memory_filter_remove(engine, memory) != 0 ||
                memory_source_remove(engine, memory, true) != 0 ||
                memory_source_remove(engine, memory, false) != 0) {
                sqlite3_finalize(statement);
                return -1;
            }
            memory->visible = false;
        }
    }
    sqlite3_finalize(statement);
    return step == SQLITE_DONE ? 0 : -1;
}

static int refuse_pending_receipts(Engine *engine, const char *operation)
{
    sqlite3_stmt *statement = NULL;
    int rc = -1;
    if (sqlite3_prepare_v2(engine->db,
            "SELECT 1 FROM operation_receipt WHERE state=0 LIMIT 1",
            -1, &statement, NULL) != SQLITE_OK) goto done;
    if (sqlite3_step(statement) == SQLITE_DONE) {
        rc = 0;
    } else {
        fprintf(stderr, "%s refused: an operation receipt is still pending replay\n",
                operation);
    }
done:
    sqlite3_finalize(statement);
    return rc;
}

static const Engine *initial_sort_engine;

static int compare_token_order_initial(const void *left, const void *right)
{
    uint64_t a = *(const uint64_t *)left;
    uint64_t b = *(const uint64_t *)right;
    return token_after(&initial_sort_engine->tokens[a], a,
                       &initial_sort_engine->tokens[b], b) ? 1 :
           token_after(&initial_sort_engine->tokens[b], b,
                       &initial_sort_engine->tokens[a], a) ? -1 : 0;
}

static int compare_links_initial(const void *left, const void *right)
{
    const Link *a = left;
    const Link *b = right;
    return link_after(a, b) ? 1 : link_after(b, a) ? -1 : 0;
}

static int compare_postings_initial(const void *left, const void *right)
{
    const Posting *a = left;
    const Posting *b = right;
    return posting_after(a, b) ? 1 : posting_after(b, a) ? -1 : 0;
}

static int compare_memories_initial(const void *left, const void *right)
{
    const Memory *a = *(Memory * const *)left;
    const Memory *b = *(Memory * const *)right;
    if (a->access_count != b->access_count)
        return a->access_count > b->access_count ? -1 : 1;
    return a->metadata.id < b->metadata.id ? -1 :
           a->metadata.id > b->metadata.id ? 1 : 0;
}

static int initial_sort(Engine *engine)
{
    initial_sort_engine = engine;
    for (size_t i = 0; i < engine->token_count; ++i) {
        engine->tokens[i].order_write_count = engine->tokens[i].write_count;
        engine->tokens[i].order_read_count = engine->tokens[i].read_count;
        engine->tokens[i].order_usage_count = engine->tokens[i].usage_count;
    }
    if (engine->token_order_count > 1)
        qsort(engine->token_order, engine->token_order_count,
              sizeof(*engine->token_order), compare_token_order_initial);
    for (size_t i = 0; i < engine->token_order_count; ++i)
        engine->tokens[engine->token_order[i]].order_position = i;
    if (engine->memory_count > 1)
        qsort(engine->memory_order, engine->memory_count,
              sizeof(*engine->memory_order), compare_memories_initial);
    for (size_t i = 0; i < engine->token_count; ++i) {
        Token *token = &engine->tokens[i];
        if (token->link_count > 1)
            qsort(token->links, token->link_count,
                  sizeof(*token->links), compare_links_initial);
        if (token->link_count != 0 &&
            rebuild_link_index(token, token->link_index_capacity) != 0) {
            initial_sort_engine = NULL;
            return -1;
        }
        if (token->posting_count > 1)
            qsort(token->postings, token->posting_count,
                  sizeof(*token->postings), compare_postings_initial);
    }
    initial_sort_engine = NULL;
    return 0;
}

static void memory_free(Memory *memory)
{
    if (memory == NULL) return;
    metadata_free(&memory->metadata);
    free(memory->key);
    free(memory->tier_name);
    free(memory->tokens);
    free(memory->metadata_tokens);
    free(memory);
}

static int engine_close(Engine *engine);
static int reconcile_neutral_accounting_intents(Engine *engine);
static int reconcile_memory_accounting(Engine *engine);
static int write_sequence_file(const char *store_path, int64_t sequence);
static int read_sequence_file(const char *store_path, int64_t fallback,
                              int64_t *sequence);

static int engine_open(Engine *engine, const char *store_path, bool with_ranker)
{
    char *lock_path;
    memset(engine, 0, sizeof(*engine));
    engine->lock_fd = -1;
    engine->store_path = xstrdup(store_path);
    engine->memory_dir = path_join(store_path, "memories");
    engine->catalog_path = path_join(store_path, "catalog.sqlite3");
    lock_path = path_join(store_path, ".lock");
    engine->lock_fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0600);
    free(lock_path);
    if (engine->lock_fd < 0 || flock(engine->lock_fd, LOCK_EX | LOCK_NB) != 0) {
        fprintf(stderr, "store is unavailable or already resident: %s\n", strerror(errno));
        goto fail;
    }
    if (sqlite3_open_v2(engine->catalog_path, &engine->db,
                        SQLITE_OPEN_READWRITE, NULL) != SQLITE_OK) {
        fprintf(stderr, "open token catalog: %s\n",
                engine->db == NULL ? "failed" : sqlite3_errmsg(engine->db));
        goto fail;
    }
    sqlite3_busy_timeout(engine->db, 5000);
    if (sqlite_exec_checked(engine->db,
            "CREATE TABLE IF NOT EXISTS operation_receipt("
            "op_key TEXT PRIMARY KEY,request_sha256 BLOB NOT NULL "
            "CHECK(length(request_sha256)=32),kind TEXT NOT NULL,"
            "memory_id INTEGER NOT NULL,state INTEGER NOT NULL "
            "CHECK(state IN (0,1))) WITHOUT ROWID") != 0) goto fail;
    if (sqlite_exec_checked(engine->db,
            "CREATE INDEX IF NOT EXISTS operation_receipt_memory_id "
            "ON operation_receipt(memory_id)") != 0) goto fail;
    if (sqlite_exec_checked(engine->db,
            "CREATE TABLE IF NOT EXISTS neutral_accounting_intent("
            "memory_id INTEGER PRIMARY KEY,tier TEXT NOT NULL,"
            "content_sha256 BLOB NOT NULL CHECK(length(content_sha256)=32),"
            "metadata_sha256 BLOB NOT NULL CHECK(length(metadata_sha256)=32))") != 0)
        goto fail;
    if (pthread_mutex_init(&engine->mutex, NULL) != 0) goto fail;
    engine->mutex_ready = true;
    if (pthread_cond_init(&engine->condition, NULL) != 0) goto fail;
    engine->condition_ready = true;
    if (load_registry(engine) != 0 || load_pair_state(engine) != 0 ||
        load_all_memories(engine) != 0 ||
        reconcile_neutral_accounting_intents(engine) != 0 ||
        reconcile_memory_accounting(engine) != 0 ||
        load_reinforcement_state(engine) != 0 ||
        load_memory_access_state(engine) != 0 ||
        validate_operation_receipts(engine) != 0 || engine->poisoned) {
        goto fail;
    }
    if (initial_sort(engine) != 0) {
        fputs("invalid resident link index\n", stderr);
        goto fail;
    }
    engine->ordering_ready = true;
    engine->order_enqueue_enabled = true;
    if (with_ranker && start_ranker(engine) != 0) goto fail;
    return 0;
fail:
    engine_close(engine);
    return -1;
}

static int engine_close(Engine *engine)
{
    int rc = 0;
    if (engine->ranker_started) {
        pthread_mutex_lock(&engine->mutex);
        atomic_store(&engine->stop_ranker, true);
        pthread_cond_signal(&engine->condition);
        pthread_mutex_unlock(&engine->mutex);
        pthread_join(engine->rank_thread, NULL);
        engine->ranker_started = false;
    }
    if (engine->mutex_ready) {
        pthread_mutex_lock(&engine->mutex);
        while (has_dirty_state(engine)) {
            if (flush_dirty_state_locked(engine, SIZE_MAX) != 0) {
                rc = -1;
                break;
            }
        }
        pthread_mutex_unlock(&engine->mutex);
    }
    for (size_t i = 0; i < engine->memory_count; ++i) memory_free(engine->memory_order[i]);
    for (size_t i = 0; i < engine->token_count; ++i) {
        free(engine->tokens[i].segment);
        free(engine->tokens[i].links);
        free(engine->tokens[i].link_index);
        free(engine->tokens[i].postings);
    }
    if (engine->db != NULL && sqlite3_close(engine->db) != SQLITE_OK) rc = -1;
    if (engine->lock_fd >= 0) close(engine->lock_fd);
    free(engine->tokens);
    free(engine->token_order);
    free(engine->order_tokens);
    free(engine->dirty_tokens);
    free(engine->dirty_pairs);
    free(engine->dirty_links);
    free(engine->order_links);
    free(engine->dirty_memories);
    free(engine->segments.slots);
    free(engine->pairs.slots);
    free(engine->memory_order);
    free(engine->memory_id_order);
    free(engine->memory_updated_order);
    free(engine->memory_source_memory_order);
    free(engine->memory_source_event_order);
    for (size_t i = 0; i < engine->memory_filter_index_count; ++i) {
        free(engine->memory_filter_indexes[i].tier);
        free(engine->memory_filter_indexes[i].status);
        free(engine->memory_filter_indexes[i].id_order);
        free(engine->memory_filter_indexes[i].updated_order);
    }
    free(engine->memory_filter_indexes);
    free(engine->query_touched);
    free(engine->query_results);
    free(engine->query_token_marks);
    trie_free(engine->trie);
    free(engine->store_path);
    free(engine->memory_dir);
    free(engine->catalog_path);
    if (engine->condition_ready) pthread_cond_destroy(&engine->condition);
    if (engine->mutex_ready) pthread_mutex_destroy(&engine->mutex);
    memset(engine, 0, sizeof(*engine));
    return rc;
}

typedef struct {
    uint64_t id;
    uint64_t old_write_count;
} WriteRollback;

typedef enum {
    MARKER_ABSENT,
    MARKER_EXACT,
    MARKER_METADATA_STALE
} MarkerState;

static void neutral_fault_point(const char *point)
{
    const char *requested = getenv("TOKMEM_FAULT_NEUTRAL");
    if (requested != NULL && strcmp(requested, point) == 0) _exit(97);
}

static void receipt_fault_point(const char *point)
{
    const char *requested = getenv("TOKMEM_FAULT_RECEIPT");
    if (requested != NULL && strcmp(requested, point) == 0) _exit(98);
}

static int neutral_intent_begin_locked(Engine *engine, int64_t memory_id,
                                       const char *tier,
                                       const unsigned char content_digest[32],
                                       const unsigned char metadata_digest[32])
{
    sqlite3_stmt *statement = NULL;
    int step;
    int rc = -1;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "SELECT tier,content_sha256,metadata_sha256 "
            "FROM neutral_accounting_intent WHERE memory_id=?1",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory_id) != SQLITE_OK) {
        goto rollback;
    }
    step = sqlite3_step(statement);
    if (step == SQLITE_ROW) {
        bool exact = sqlite3_column_type(statement, 0) != SQLITE_NULL &&
            sqlite3_column_bytes(statement, 1) == 32 &&
            sqlite3_column_bytes(statement, 2) == 32 &&
            strcmp((const char *)sqlite3_column_text(statement, 0), tier) == 0 &&
            memcmp(sqlite3_column_blob(statement, 1), content_digest, 32) == 0 &&
            memcmp(sqlite3_column_blob(statement, 2), metadata_digest, 32) == 0;
        sqlite3_finalize(statement);
        statement = NULL;
        if (!exact || sqlite_exec_checked(engine->db, "COMMIT") != 0)
            goto rollback;
        return 0;
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (step != SQLITE_DONE || sqlite3_prepare_v2(engine->db,
            "INSERT INTO neutral_accounting_intent("
            "memory_id,tier,content_sha256,metadata_sha256) VALUES(?1,?2,?3,?4)",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory_id) != SQLITE_OK ||
        sqlite3_bind_text(statement, 2, tier, -1, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_bind_blob(statement, 3, content_digest, 32, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_bind_blob(statement, 4, metadata_digest, 32, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        goto rollback;
    }
    rc = 0;
    goto done;
rollback:
    sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int neutral_intent_delete_locked(Engine *engine, int64_t memory_id)
{
    sqlite3_stmt *statement = NULL;
    int rc = -1;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "DELETE FROM neutral_accounting_intent WHERE memory_id=?1",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory_id) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        goto done;
    }
    rc = 0;
done:
    sqlite3_finalize(statement);
    return rc;
}

static int accounting_marker(Engine *engine, const Memory *memory,
                             MarkerState *state)
{
    sqlite3_stmt *statement = NULL;
    int rc = sqlite3_prepare_v2(engine->db,
        "SELECT tier,content_sha256,metadata_sha256 FROM memory_accounting "
        "WHERE memory_id=?1", -1, &statement, NULL);
    if (rc == SQLITE_OK) rc = sqlite3_bind_int64(statement, 1, memory->metadata.id);
    if (rc == SQLITE_OK) rc = sqlite3_step(statement);
    if (rc == SQLITE_DONE) {
        *state = MARKER_ABSENT;
        sqlite3_finalize(statement);
        return 0;
    }
    if (rc != SQLITE_ROW || sqlite3_column_type(statement, 0) == SQLITE_NULL ||
        sqlite3_column_bytes(statement, 1) != 32 ||
        sqlite3_column_bytes(statement, 2) != 32 ||
        memcmp(sqlite3_column_blob(statement, 1), memory->content_digest, 32) != 0) {
        fputs("memory accounting marker does not match immutable content\n", stderr);
        sqlite3_finalize(statement);
        return -1;
    }
    *state = strcmp((const char *)sqlite3_column_text(statement, 0),
                    memory->tier_name) == 0 &&
             memcmp(sqlite3_column_blob(statement, 2),
                    memory->metadata_digest, 32) == 0 ?
             MARKER_EXACT : MARKER_METADATA_STALE;
    sqlite3_finalize(statement);
    return 0;
}

static int account_memory_locked(Engine *engine, Memory *memory)
{
    TokenVector all = {0};
    WriteRollback *rollback = NULL;
    size_t rollback_count = 0;
    sqlite3_stmt *update = NULL;
    sqlite3_stmt *insert = NULL;
    MarkerState marker;
    int rc = -1;

    if (accounting_marker(engine, memory, &marker) != 0) return -1;
    if (marker == MARKER_EXACT) return 0;
    if (marker == MARKER_ABSENT) {
        if (memory->token_count > SIZE_MAX - memory->metadata_token_count) return -1;
        for (size_t i = 0; i < memory->token_count; ++i)
            token_vector_push(&all, memory->tokens[i]);
    }
    for (size_t i = 0; i < memory->metadata_token_count; ++i)
        token_vector_push(&all, memory->metadata_tokens[i]);
    if (all.count > 1) qsort(all.items, all.count, sizeof(*all.items), compare_u64);
    rollback = xmalloc(all.count * sizeof(*rollback));
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "UPDATE token_translation SET write_count=?1,read_count=?2,usage_count=?3 "
            "WHERE token_id=?4", -1, &update, NULL) != SQLITE_OK ||
        sqlite3_prepare_v2(engine->db,
            "INSERT INTO memory_accounting(memory_id,tier,content_sha256,metadata_sha256) "
            "VALUES(?1,?2,?3,?4) ON CONFLICT(memory_id) DO UPDATE SET "
            "tier=excluded.tier,content_sha256=excluded.content_sha256,"
            "metadata_sha256=excluded.metadata_sha256", -1, &insert, NULL) != SQLITE_OK) {
        goto rollback_sql;
    }
    for (size_t position = 0; position < all.count;) {
        size_t end = position + 1;
        uint64_t id = all.items[position];
        Token *token = &engine->tokens[id];
        uint64_t occurrences;
        unsigned char writes[8], reads[8], usage[8];
        while (end < all.count && all.items[end] == id) ++end;
        occurrences = (uint64_t)(end - position);
        rollback[rollback_count++] = (WriteRollback){id, token->write_count};
        token->write_count = MAX_COUNTER - token->write_count < occurrences ?
                             MAX_COUNTER : token->write_count + occurrences;
        enqueue_order_token(engine, id);
        put_u64_le(writes, token->write_count);
        put_u64_le(reads, token->read_count);
        put_u64_le(usage, token->usage_count);
        sqlite3_bind_blob(update, 1, writes, 8, SQLITE_TRANSIENT);
        sqlite3_bind_blob(update, 2, reads, 8, SQLITE_TRANSIENT);
        sqlite3_bind_blob(update, 3, usage, 8, SQLITE_TRANSIENT);
        sqlite3_bind_int64(update, 4, (sqlite3_int64)id);
        if (sqlite3_step(update) != SQLITE_DONE || sqlite3_changes(engine->db) != 1)
            goto rollback_sql;
        sqlite3_reset(update);
        sqlite3_clear_bindings(update);
        position = end;
    }
    sqlite3_bind_int64(insert, 1, memory->metadata.id);
    sqlite3_bind_text(insert, 2, memory->tier_name, -1, SQLITE_STATIC);
    sqlite3_bind_blob(insert, 3, memory->content_digest, 32, SQLITE_STATIC);
    sqlite3_bind_blob(insert, 4, memory->metadata_digest, 32, SQLITE_STATIC);
    if (sqlite3_step(insert) != SQLITE_DONE ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        goto rollback_sql;
    }
    rc = 0;
    goto done;
rollback_sql:
    sqlite_exec_checked(engine->db, "ROLLBACK");
    for (size_t i = 0; i < rollback_count; ++i)
        engine->tokens[rollback[i].id].write_count = rollback[i].old_write_count;
done:
    sqlite3_finalize(update);
    sqlite3_finalize(insert);
    free(rollback);
    free(all.items);
    return rc;
}

static int finalize_neutral_intent_locked(Engine *engine, Memory *memory)
{
    sqlite3_stmt *statement = NULL;
    MarkerState marker;
    int step;
    int rc = -1;

    if (sqlite3_prepare_v2(engine->db,
            "SELECT tier,content_sha256,metadata_sha256 "
            "FROM neutral_accounting_intent WHERE memory_id=?1",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory->metadata.id) != SQLITE_OK)
        goto done;
    step = sqlite3_step(statement);
    if (step != SQLITE_ROW || sqlite3_column_type(statement, 0) == SQLITE_NULL ||
        sqlite3_column_bytes(statement, 1) != 32 ||
        sqlite3_column_bytes(statement, 2) != 32 ||
        strcmp((const char *)sqlite3_column_text(statement, 0),
               memory->tier_name) != 0 ||
        memcmp(sqlite3_column_blob(statement, 1), memory->content_digest, 32) != 0 ||
        memcmp(sqlite3_column_blob(statement, 2), memory->metadata_digest, 32) != 0) {
        fputs("neutral accounting intent does not match published memory\n", stderr);
        goto done;
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (accounting_marker(engine, memory, &marker) != 0 ||
        sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "INSERT INTO memory_accounting(memory_id,tier,content_sha256,metadata_sha256) "
            "VALUES(?1,?2,?3,?4) ON CONFLICT(memory_id) DO UPDATE SET "
            "tier=excluded.tier,content_sha256=excluded.content_sha256,"
            "metadata_sha256=excluded.metadata_sha256",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory->metadata.id) != SQLITE_OK ||
        sqlite3_bind_text(statement, 2, memory->tier_name, -1, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_bind_blob(statement, 3, memory->content_digest, 32, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_bind_blob(statement, 4, memory->metadata_digest, 32, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE) {
        goto rollback;
    }
    sqlite3_finalize(statement);
    statement = NULL;
    if (sqlite3_prepare_v2(engine->db,
            "DELETE FROM neutral_accounting_intent WHERE memory_id=?1",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, memory->metadata.id) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE || sqlite3_changes(engine->db) != 1 ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        goto rollback;
    }
    (void)marker;
    rc = 0;
    goto done;
rollback:
    sqlite_exec_checked(engine->db, "ROLLBACK");
done:
    sqlite3_finalize(statement);
    return rc;
}

static int mark_memory_accounted_neutral_locked(Engine *engine, Memory *memory)
{
    return finalize_neutral_intent_locked(engine, memory);
}

static int refresh_metadata_accounting_neutral_locked(Engine *engine,
                                                       Memory *memory)
{
    return finalize_neutral_intent_locked(engine, memory);
}

typedef struct {
    int64_t memory_id;
    char *tier;
    unsigned char content_digest[32];
    unsigned char metadata_digest[32];
} NeutralIntent;

static int reconcile_neutral_accounting_intents(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    NeutralIntent *intents = NULL;
    size_t count = 0;
    size_t capacity = 0;
    int step;
    int rc = -1;

    if (sqlite3_prepare_v2(engine->db,
            "SELECT memory_id,tier,content_sha256,metadata_sha256 "
            "FROM neutral_accounting_intent ORDER BY memory_id",
            -1, &statement, NULL) != SQLITE_OK) return -1;
    while ((step = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 id = sqlite3_column_int64(statement, 0);
        const unsigned char *tier = sqlite3_column_text(statement, 1);
        int tier_size = sqlite3_column_bytes(statement, 1);
        if (id <= 0 || tier == NULL || tier_size <= 0 ||
            sqlite3_column_bytes(statement, 2) != 32 ||
            sqlite3_column_bytes(statement, 3) != 32) goto done;
        if (count == capacity) {
            capacity = capacity == 0 ? 8 : capacity * 2;
            intents = xrealloc(intents, capacity * sizeof(*intents));
        }
        intents[count].memory_id = id;
        intents[count].tier = xmalloc((size_t)tier_size + 1);
        memcpy(intents[count].tier, tier, (size_t)tier_size);
        intents[count].tier[tier_size] = '\0';
        memcpy(intents[count].content_digest, sqlite3_column_blob(statement, 2), 32);
        memcpy(intents[count].metadata_digest, sqlite3_column_blob(statement, 3), 32);
        ++count;
    }
    if (step != SQLITE_DONE) goto done;
    sqlite3_finalize(statement);
    statement = NULL;

    for (size_t i = 0; i < count; ++i) {
        NeutralIntent *intent = &intents[i];
        Memory *memory = memory_find_id(engine, intent->memory_id);
        if (memory == NULL) {
            if (neutral_intent_delete_locked(engine, intent->memory_id) != 0) goto done;
            continue;
        }
        if (strcmp(memory->tier_name, intent->tier) != 0 ||
            memcmp(memory->content_digest, intent->content_digest, 32) != 0) {
            fputs("published memory conflicts with neutral accounting intent\n", stderr);
            goto done;
        }
        if (memcmp(memory->metadata_digest, intent->metadata_digest, 32) == 0) {
            if (finalize_neutral_intent_locked(engine, memory) != 0) goto done;
        } else {
            MarkerState marker;
            if (accounting_marker(engine, memory, &marker) != 0 ||
                marker != MARKER_EXACT) {
                fputs("ambiguous pre-publication neutral metadata intent\n", stderr);
                goto done;
            }
            if (neutral_intent_delete_locked(engine, intent->memory_id) != 0) goto done;
        }
    }
    rc = 0;
done:
    sqlite3_finalize(statement);
    for (size_t i = 0; i < count; ++i) free(intents[i].tier);
    free(intents);
    return rc;
}

static int reconcile_memory_accounting(Engine *engine)
{
    sqlite3_stmt *statement = NULL;
    size_t marker_count = 0;
    int step;
    if (sqlite3_prepare_v2(engine->db,
            "SELECT memory_id,tier,content_sha256,metadata_sha256 "
            "FROM memory_accounting", -1, &statement, NULL) != SQLITE_OK) {
        return -1;
    }
    while ((step = sqlite3_step(statement)) == SQLITE_ROW) {
        sqlite3_int64 raw_id = sqlite3_column_int64(statement, 0);
        Memory *memory = raw_id > 0 ? memory_find_id(engine, raw_id) : NULL;
        if (memory == NULL || sqlite3_column_type(statement, 1) == SQLITE_NULL ||
            sqlite3_column_bytes(statement, 2) != 32 ||
            sqlite3_column_bytes(statement, 3) != 32 ||
            memcmp(sqlite3_column_blob(statement, 2), memory->content_digest, 32) != 0) {
            fputs("orphaned or mismatched memory accounting marker\n", stderr);
            sqlite3_finalize(statement);
            return -1;
        }
        ++marker_count;
    }
    sqlite3_finalize(statement);
    if (step != SQLITE_DONE || marker_count > engine->memory_count) {
        fputs("memory accounting is not bijective with published memories\n", stderr);
        return -1;
    }
    for (size_t i = 0; i < engine->memory_count; ++i) {
        if (account_memory_locked(engine, engine->memory_order[i]) != 0) return -1;
    }
    return 0;
}

static int delete_trailing_tokens_locked(Engine *engine, size_t first_token)
{
    sqlite3_stmt *statement = NULL;
    size_t expected;
    int rc = -1;
    if (first_token > engine->token_count || first_token > INT64_MAX) return -1;
    expected = engine->token_count - first_token;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "DELETE FROM token_translation WHERE token_id>=?1",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 1, (sqlite3_int64)first_token) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE ||
        (size_t)sqlite3_changes(engine->db) != expected ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        goto done;
    }
    rc = 0;
done:
    sqlite3_finalize(statement);
    return rc;
}

static int add_record(Engine *engine, Metadata *metadata,
                      const unsigned char *content, size_t content_size,
                      bool cognitive_write, bool visible)
{
    unsigned char *metadata_text = NULL;
    size_t metadata_text_size = 0;
    TokenVector content_tokens = {0};
    TokenVector metadata_tokens = {0};
    PairMap pair_deltas = {0};
    unsigned char *content_blob = NULL;
    unsigned char *metadata_blob = NULL;
    size_t content_blob_size = 0;
    size_t metadata_blob_size = 0;
    unsigned char content_digest[32];
    unsigned char metadata_digest[32];
    char key[64];
    const char *tier;
    int64_t record_id;
    int rc = -1;
    int publication_rc = PUBLISH_CLEAN_FAILURE;
    bool transaction = false;
    bool token_commit = false;
    bool neutral_intent_pending = false;
    bool record_published = false;
    size_t initial_token_count;

    record_id = metadata->id;
    snprintf(key, sizeof(key), "%" PRId64, record_id);
    tier = (const char *)metadata->tier.data;
    if (metadata->id <= 0 || safe_name(metadata->tier.data, metadata->tier.size) != 0 ||
        metadata->tier.data[metadata->tier.size] != '\0') {
        fputs("invalid memory metadata identity/tier\n", stderr);
        return -1;
    }
    metadata_encode(metadata, &metadata_text, &metadata_text_size);
    pthread_mutex_lock(&engine->mutex);
    initial_token_count = engine->token_count;
    if (memory_find(engine, tier, key) != NULL || memory_find_id(engine, metadata->id) != NULL) {
        fprintf(stderr, "memory %s/%s already exists\n", tier, key);
        goto done;
    }
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (encode_greedy(engine, content, content_size, &content_tokens) != 0 ||
        crystallize_online(engine, &content_tokens, &pair_deltas) != 0 ||
        encode_greedy(engine, metadata_text, metadata_text_size, &metadata_tokens) != 0 ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        goto done;
    }
    transaction = false;
    token_commit = true;
    if (!posting_expansion_within_budget(engine, content_tokens.items,
                                         content_tokens.count)) {
        fputs("content expansion exceeds the resident posting budget\n", stderr);
        goto done;
    }
    if (encode_memory_blob(&content_tokens, &content_blob, &content_blob_size) != 0 ||
        encode_memory_blob(&metadata_tokens, &metadata_blob, &metadata_blob_size) != 0) {
        goto done;
    }
    sha256_bytes(content_blob, content_blob_size, content_digest);
    sha256_bytes(metadata_blob, metadata_blob_size, metadata_digest);
    if (!cognitive_write) {
        if (neutral_intent_begin_locked(engine, record_id, tier, content_digest,
                                        metadata_digest) != 0) goto done;
        neutral_intent_pending = true;
        neutral_fault_point("after_intent");
    }
    publication_rc = publish_record_files(engine, tier, key,
                                          content_blob, content_blob_size,
                                          metadata_blob, metadata_blob_size);
    if (publication_rc != 0) goto done;
    record_published = true;
    if (!cognitive_write) neutral_fault_point("after_publish");
    {
        bool saved_order_enqueue = engine->order_enqueue_enabled;
        int resident_rc;
        engine->order_enqueue_enabled = cognitive_write;
        resident_rc = memory_publish_resident(engine, metadata, &content_tokens,
                                              &metadata_tokens, content_digest,
                                              metadata_digest, visible);
        engine->order_enqueue_enabled = saved_order_enqueue;
        if (resident_rc != 0) {
            engine->poisoned = true;
            goto done;
        }
    }
    if ((cognitive_write ?
         account_memory_locked(engine, memory_find_id(engine, strtoll(key, NULL, 10))) :
         mark_memory_accounted_neutral_locked(
             engine, memory_find_id(engine, strtoll(key, NULL, 10)))) != 0) {
        engine->poisoned = true;
        goto done;
    }
    neutral_intent_pending = false;
    if (cognitive_write) {
        int64_t sequence;
        if (read_sequence_file(engine->store_path, record_id, &sequence) != 0) {
            engine->poisoned = true;
            goto done;
        }
        if (sequence < record_id) sequence = record_id;
        if (
            write_sequence_file(engine->store_path, sequence) != 0) {
            engine->poisoned = true;
            goto done;
        }
    }
    apply_pair_deltas(engine, &pair_deltas);
    rc = 0;
done:
    if (transaction) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        if (engine->token_count != initial_token_count) engine->poisoned = true;
    }
    if (rc != 0 && token_commit && !record_published) {
        if (neutral_intent_pending && publication_rc == PUBLISH_CLEAN_FAILURE) {
            if (neutral_intent_delete_locked(engine, record_id) != 0)
                engine->poisoned = true;
            neutral_intent_pending = false;
        }
        if (publication_rc == PUBLISH_AMBIGUOUS_FAILURE) {
            engine->poisoned = true;
        } else if (engine->token_count != initial_token_count) {
            if (delete_trailing_tokens_locked(engine, initial_token_count) != 0)
                fputs("could not roll back unpublished token identities\n", stderr);
            engine->poisoned = true;
        }
    }
    pthread_mutex_unlock(&engine->mutex);
    free(metadata_text);
    free(content_tokens.items);
    free(metadata_tokens.items);
    free(pair_deltas.slots);
    free(content_blob);
    free(metadata_blob);
    return rc;
}

static int current_utc(Bytes *result)
{
    time_t now = time(NULL);
    struct tm value;
    char text[32];
    size_t size;
    if (gmtime_r(&now, &value) == NULL) return -1;
    size = strftime(text, sizeof(text), "%Y-%m-%d %H:%M:%S", &value);
    if (size == 0) return -1;
    *result = bytes_copy((const unsigned char *)text, size);
    return 0;
}

static int add_default_record(Engine *engine, const char *tier, const char *key,
                              const unsigned char *content, size_t content_size)
{
    Metadata metadata = {0};
    int rc;
    if (safe_name((const unsigned char *)tier, strlen(tier)) != 0 ||
        parse_memory_id(key, &metadata.id) != 0 ||
        current_utc(&metadata.created_at) != 0) {
        fputs("invalid tier/id for add\n", stderr);
        return -1;
    }
    metadata.updated_at = bytes_copy(metadata.created_at.data, metadata.created_at.size);
    metadata.tier = bytes_copy((const unsigned char *)tier, strlen(tier));
    metadata.confidence = 1.0;
    metadata.status = bytes_copy((const unsigned char *)"active", 6);
    rc = add_record(engine, &metadata, content, content_size, true, true);
    metadata_free(&metadata);
    return rc;
}

static bool bytes_equal(const Bytes *left, const Bytes *right)
{
    return left->size == right->size &&
           (left->size == 0 || memcmp(left->data, right->data, left->size) == 0);
}

static bool nullable_id_equal(bool left_has, int64_t left,
                              bool right_has, int64_t right)
{
    return left_has == right_has && (!left_has || left == right);
}

static bool metadata_semantically_equal(const Metadata *left,
                                        const Metadata *right)
{
    uint64_t left_confidence;
    uint64_t right_confidence;
    memcpy(&left_confidence, &left->confidence, sizeof(left_confidence));
    memcpy(&right_confidence, &right->confidence, sizeof(right_confidence));
    return left->id == right->id && bytes_equal(&left->tier, &right->tier) &&
           left_confidence == right_confidence &&
           bytes_equal(&left->status, &right->status) &&
           nullable_id_equal(left->has_source_event_id, left->source_event_id,
                             right->has_source_event_id, right->source_event_id) &&
           left->source_event_kind == right->source_event_kind &&
           nullable_id_equal(left->has_source_memory_id, left->source_memory_id,
                             right->has_source_memory_id, right->source_memory_id) &&
           nullable_id_equal(left->has_supersedes_id, left->supersedes_id,
                             right->has_supersedes_id, right->supersedes_id) &&
           left->has_expires_at == right->has_expires_at &&
           (!left->has_expires_at || bytes_equal(&left->expires_at,
                                                 &right->expires_at));
}

static bool metadata_exactly_equal(const Metadata *left, const Metadata *right)
{
    return metadata_semantically_equal(left, right) &&
           bytes_equal(&left->created_at, &right->created_at) &&
           bytes_equal(&left->updated_at, &right->updated_at);
}

static void operation_request_digest(const Metadata *first,
                                     const Metadata *second,
                                     const unsigned char *content,
                                     size_t content_size,
                                     unsigned char digest[32])
{
    Metadata canonical = *first;
    unsigned char *encoded = NULL;
    size_t encoded_size = 0;
    Sha256 sha;
    canonical.created_at = (Bytes){0};
    canonical.updated_at = (Bytes){0};
    metadata_encode(&canonical, &encoded, &encoded_size);
    sha256_init(&sha);
    sha256_update(&sha, encoded, encoded_size);
    free(encoded);
    if (second != NULL) {
        canonical = *second;
        canonical.created_at = (Bytes){0};
        canonical.updated_at = (Bytes){0};
        metadata_encode(&canonical, &encoded, &encoded_size);
        sha256_update(&sha, encoded, encoded_size);
        free(encoded);
    }
    sha256_update(&sha, content, content_size);
    sha256_final(&sha, digest);
}

static int next_memory_id(Engine *engine, int64_t *id)
{
    int64_t maximum = 0;
    int64_t sequence;
    sqlite3_stmt *statement = NULL;
    /* The ID index includes hidden pending records as well as visible ones. */
    if (engine->memory_count != 0)
        maximum = engine->memory_id_order[engine->memory_count - 1]->metadata.id;
    if (sqlite3_prepare_v2(engine->db,
            "SELECT COALESCE(MAX(memory_id),0) FROM operation_receipt",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_ROW) {
        sqlite3_finalize(statement);
        return -1;
    }
    if (sqlite3_column_int64(statement, 0) > maximum)
        maximum = sqlite3_column_int64(statement, 0);
    sqlite3_finalize(statement);
    if (read_sequence_file(engine->store_path, maximum, &sequence) != 0)
        return -1;
    if (sequence > maximum) maximum = sequence;
    if (maximum == INT64_MAX) return -1;
    *id = maximum + 1;
    return 0;
}

static bool valid_operation_key(const char *key)
{
    size_t size = strlen(key);
    if (size != 64) return false;
    for (size_t i = 0; i < size; ++i) {
        if (!((key[i] >= '0' && key[i] <= '9') ||
              (key[i] >= 'a' && key[i] <= 'f'))) return false;
    }
    return true;
}

static int receipt_begin(Engine *engine, const char *key,
                         const unsigned char digest[32], const char *kind,
                         int64_t requested_id, int64_t *memory_id,
                         bool *complete)
{
    sqlite3_stmt *statement = NULL;
    int step;
    int rc = -1;
    if (!valid_operation_key(key)) return -1;
    pthread_mutex_lock(&engine->mutex);
    if (sqlite3_prepare_v2(engine->db,
            "SELECT request_sha256,kind,memory_id,state FROM operation_receipt "
            "WHERE op_key=?1", -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_text(statement, 1, key, -1, SQLITE_TRANSIENT) != SQLITE_OK)
        goto done;
    step = sqlite3_step(statement);
    if (step == SQLITE_ROW) {
        if (sqlite3_column_bytes(statement, 0) != 32 ||
            memcmp(sqlite3_column_blob(statement, 0), digest, 32) != 0 ||
            sqlite3_column_type(statement, 1) == SQLITE_NULL ||
            strcmp((const char *)sqlite3_column_text(statement, 1), kind) != 0 ||
            sqlite3_column_int64(statement, 2) <= 0 ||
            (requested_id > 0 && sqlite3_column_int64(statement, 2) != requested_id) ||
            (sqlite3_column_int(statement, 3) != 0 &&
             sqlite3_column_int(statement, 3) != 1)) {
            fputs("operation key was reused with a different request\n", stderr);
            goto done;
        }
        *memory_id = sqlite3_column_int64(statement, 2);
        *complete = sqlite3_column_int(statement, 3) == 1;
        rc = 0;
        goto done;
    }
    if (step != SQLITE_DONE) goto done;
    sqlite3_finalize(statement);
    statement = NULL;
    if (requested_id > 0) *memory_id = requested_id;
    else if (next_memory_id(engine, memory_id) != 0) goto done;
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "INSERT INTO operation_receipt(op_key,request_sha256,kind,memory_id,state) "
            "VALUES(?1,?2,?3,?4,0)", -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_text(statement, 1, key, -1, SQLITE_TRANSIENT) != SQLITE_OK ||
        sqlite3_bind_blob(statement, 2, digest, 32, SQLITE_TRANSIENT) != SQLITE_OK ||
        sqlite3_bind_text(statement, 3, kind, -1, SQLITE_STATIC) != SQLITE_OK ||
        sqlite3_bind_int64(statement, 4, *memory_id) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        goto done;
    }
    *complete = false;
    rc = 0;
done:
    sqlite3_finalize(statement);
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static int receipt_finish(Engine *engine, const char *key,
                          int64_t memory_id, bool reveal_memory)
{
    sqlite3_stmt *statement = NULL;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(engine->db,
            "UPDATE operation_receipt SET state=1 WHERE op_key=?1 AND state=0",
            -1, &statement, NULL) != SQLITE_OK ||
        sqlite3_bind_text(statement, 1, key, -1, SQLITE_TRANSIENT) != SQLITE_OK ||
        sqlite3_step(statement) != SQLITE_DONE) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        goto done;
    }
    if (sqlite3_changes(engine->db) == 0) {
        sqlite3_finalize(statement);
        statement = NULL;
        if (sqlite3_prepare_v2(engine->db,
                "SELECT state FROM operation_receipt WHERE op_key=?1",
                -1, &statement, NULL) != SQLITE_OK ||
            sqlite3_bind_text(statement, 1, key, -1, SQLITE_TRANSIENT) != SQLITE_OK ||
            sqlite3_step(statement) != SQLITE_ROW ||
            sqlite3_column_int(statement, 0) != 1) {
            sqlite_exec_checked(engine->db, "ROLLBACK");
            goto done;
        }
    }
    if (sqlite_exec_checked(engine->db, "COMMIT") != 0) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        goto done;
    }
    if (reveal_memory) {
        Memory *memory = memory_find_id(engine, memory_id);
        if (memory == NULL) goto done;
        if (!memory->visible) {
            memory->visible = true;
            memory_filter_insert(engine, memory);
            memory_source_insert(engine, memory, true);
            memory_source_insert(engine, memory, false);
        }
    }
    rc = 0;
done:
    sqlite3_finalize(statement);
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static bool memory_content_equals(const Engine *engine, const Memory *memory,
                                   const unsigned char *content, size_t content_size)
{
    size_t offset = 0;
    if (content_size != memory->content_byte_count ||
        content_size > MAX_MEMORY_BLOB_BYTES) return false;
    for (size_t i = 0; i < memory->token_count; ++i) {
        uint64_t id = memory->tokens[i];
        const Token *token;
        if (id >= engine->token_count) return false;
        token = &engine->tokens[id];
        if (token->segment_size > content_size - offset ||
            (token->segment_size != 0 &&
             memcmp(token->segment, content + offset, token->segment_size) != 0))
            return false;
        offset += token->segment_size;
    }
    return offset == content_size;
}

static bool record_matches(Engine *engine, int64_t id, const Metadata *metadata,
                           const unsigned char *content, size_t content_size)
{
    Memory *memory;
    bool matches = false;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find_id(engine, id);
    if (memory == NULL) goto done;
    if (!metadata_exactly_equal(metadata, &memory->metadata) ||
        !memory_content_equals(engine, memory, content, content_size)) goto done;
    matches = true;
done:
    pthread_mutex_unlock(&engine->mutex);
    return matches;
}

static bool record_semantically_matches(Engine *engine, int64_t id,
                                        const Metadata *metadata,
                                        const unsigned char *content,
                                        size_t content_size)
{
    Memory *memory;
    bool matches = false;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find_id(engine, id);
    if (memory == NULL ||
        !metadata_semantically_equal(&memory->metadata, metadata)) goto done;
    if (!memory_content_equals(engine, memory, content, content_size)) goto done;
    matches = true;
done:
    pthread_mutex_unlock(&engine->mutex);
    return matches;
}

static int copy_memory_content(Engine *engine, int64_t id,
                               unsigned char **content, size_t *content_size)
{
    Memory *memory;
    TokenVector tokens;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find_id(engine, id);
    if (memory == NULL) goto done;
    tokens = (TokenVector){memory->tokens, memory->token_count, memory->token_count};
    rc = decode_tokens_to_bytes(engine, &tokens, content, content_size);
done:
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static bool episodic_reactivation_requested(Engine *engine,
                                            const Metadata *metadata)
{
    Memory *memory;
    bool reactivation = false;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find_id(engine, metadata->id);
    if (memory != NULL && strcmp(memory->tier_name, "episodic") == 0 &&
        !(memory->metadata.status.size == 6 &&
          memcmp(memory->metadata.status.data, "active", 6) == 0) &&
        metadata->status.size == 6 &&
        memcmp(metadata->status.data, "active", 6) == 0)
        reactivation = true;
    pthread_mutex_unlock(&engine->mutex);
    return reactivation;
}

static int update_record_metadata(Engine *engine, Metadata *metadata,
                                  const unsigned char *content,
                                  size_t content_size, bool cognitive_write)
{
    Memory *memory;
    unsigned char *metadata_text = NULL;
    size_t metadata_text_size = 0;
    TokenVector metadata_tokens = {0};
    TokenVector existing_tokens;
    unsigned char *content_blob = NULL;
    size_t content_blob_size = 0;
    unsigned char *metadata_blob = NULL;
    size_t metadata_blob_size = 0;
    unsigned char metadata_digest[32];
    size_t initial_token_count;
    bool transaction = false;
    bool token_commit = false;
    bool neutral_intent_pending = false;
    int publication_rc = PUBLISH_CLEAN_FAILURE;
    int rc = -1;

    if (metadata->id <= 0 || metadata->tier.data == NULL ||
        metadata->tier.data[metadata->tier.size] != '\0') return -1;
    metadata_encode(metadata, &metadata_text, &metadata_text_size);
    pthread_mutex_lock(&engine->mutex);
    initial_token_count = engine->token_count;
    memory = memory_find_id(engine, metadata->id);
    if (memory == NULL || strcmp(memory->tier_name,
                                 (const char *)metadata->tier.data) != 0 ||
        !bytes_equal(&memory->metadata.created_at, &metadata->created_at)) {
        fputs("metadata update cannot change memory identity, tier, or creation time\n",
              stderr);
        goto done;
    }
    if (strcmp(memory->tier_name, "episodic") == 0 &&
        !(memory->metadata.status.size == 6 &&
          memcmp(memory->metadata.status.data, "active", 6) == 0) &&
        metadata->status.size == 6 &&
        memcmp(metadata->status.data, "active", 6) == 0) {
        fputs("episodic memory status cannot be reactivated\n", stderr);
        goto done;
    }
    existing_tokens = (TokenVector){memory->tokens, memory->token_count,
                                    memory->token_count};
    if (!memory_content_equals(engine, memory, content, content_size)) {
        fputs("memory content is immutable; create a replacement memory\n", stderr);
        goto done;
    }
    if (metadata_exactly_equal(&memory->metadata, metadata)) {
        rc = 0;
        goto done;
    }
    if (sqlite_exec_checked(engine->db, "BEGIN IMMEDIATE") != 0) goto done;
    transaction = true;
    if (encode_greedy(engine, metadata_text, metadata_text_size,
                      &metadata_tokens) != 0 ||
        sqlite_exec_checked(engine->db, "COMMIT") != 0) goto done;
    transaction = false;
    token_commit = true;
    if (encode_memory_blob(&existing_tokens, &content_blob, &content_blob_size) != 0 ||
        encode_memory_blob(&metadata_tokens, &metadata_blob,
                           &metadata_blob_size) != 0) goto done;
    sha256_bytes(metadata_blob, metadata_blob_size, metadata_digest);
    if (!cognitive_write) {
        if (neutral_intent_begin_locked(engine, metadata->id,
                (const char *)metadata->tier.data, memory->content_digest,
                metadata_digest) != 0) goto done;
        neutral_intent_pending = true;
        neutral_fault_point("after_intent");
    }
    publication_rc = publish_metadata_replacement(engine, memory,
                                                   content_blob,
                                                   content_blob_size,
                                                   metadata_blob,
                                                   metadata_blob_size,
                                                   metadata_digest);
    if (publication_rc != 0) goto done;
    if (!cognitive_write) neutral_fault_point("after_publish");

    if (memory->visible &&
        (memory_filter_remove(engine, memory) != 0 ||
         memory_source_remove(engine, memory, true) != 0 ||
         memory_source_remove(engine, memory, false) != 0)) {
        engine->poisoned = true;
        goto done;
    }
    metadata_free(&memory->metadata);
    memory->metadata = *metadata;
    memset(metadata, 0, sizeof(*metadata));
    free(memory->metadata_tokens);
    memory->metadata_tokens = metadata_tokens.items;
    memory->metadata_token_count = metadata_tokens.count;
    memset(&metadata_tokens, 0, sizeof(metadata_tokens));
    memcpy(memory->metadata_digest, metadata_digest, sizeof(metadata_digest));
    if (memory_reindex_updated(engine, memory) != 0) {
        engine->poisoned = true;
        goto done;
    }
    if (memory->visible) {
        memory_filter_insert(engine, memory);
        memory_source_insert(engine, memory, true);
        memory_source_insert(engine, memory, false);
    }
    if ((cognitive_write ? account_memory_locked(engine, memory) :
         refresh_metadata_accounting_neutral_locked(engine, memory)) != 0) {
        engine->poisoned = true;
        goto done;
    }
    neutral_intent_pending = false;
    rc = 0;

done:
    if (transaction) {
        sqlite_exec_checked(engine->db, "ROLLBACK");
        if (engine->token_count != initial_token_count) engine->poisoned = true;
    }
    if (rc != 0 && token_commit && publication_rc != 0 &&
        engine->token_count != initial_token_count) {
        if (neutral_intent_pending && publication_rc == PUBLISH_CLEAN_FAILURE) {
            if (neutral_intent_delete_locked(engine, metadata->id) != 0)
                engine->poisoned = true;
            neutral_intent_pending = false;
        }
        if (delete_trailing_tokens_locked(engine, initial_token_count) != 0)
            fputs("could not roll back unpublished metadata tokens\n", stderr);
        engine->poisoned = true;
    }
    if (publication_rc == PUBLISH_AMBIGUOUS_FAILURE) engine->poisoned = true;
    pthread_mutex_unlock(&engine->mutex);
    free(metadata_text);
    free(metadata_tokens.items);
    free(content_blob);
    free(metadata_blob);
    return rc;
}

static int append_token_sequence(Buffer *out, const uint64_t *tokens, size_t count)
{
    if (count > (MAX_RESPONSE_BYTES - out->count) / 22) return -1;
    for (size_t i = 0; i < count; ++i) {
        if (i != 0) buffer_append_text(out, " ");
        buffer_printf(out, "%" PRIu64, tokens[i]);
    }
    buffer_append_text(out, "\n");
    return out->count <= MAX_RESPONSE_BYTES ? 0 : -1;
}

static int append_memory_content(const Engine *engine, const Memory *memory,
                                  Buffer *out)
{
    size_t start = out->count;
    size_t written = 0;
    if (memory->content_byte_count > MAX_MEMORY_BLOB_BYTES ||
        start > MAX_RESPONSE_BYTES ||
        memory->content_byte_count > MAX_RESPONSE_BYTES - start) return -1;
    buffer_reserve(out, memory->content_byte_count);
    for (size_t i = 0; i < memory->token_count; ++i) {
        uint64_t id = memory->tokens[i];
        const Token *token;
        if (id >= engine->token_count) goto invalid;
        token = &engine->tokens[id];
        if (token->segment_size > memory->content_byte_count - written) goto invalid;
        if (token->segment_size != 0)
            buffer_append(out, token->segment, token->segment_size);
        written += token->segment_size;
    }
    if (written != memory->content_byte_count) goto invalid;
    return 0;
invalid:
    out->count = start;
    return -1;
}

enum { RECORD_WIRE_CAPACITY_EXCEEDED = -2 };

static int append_record_wire(const Engine *engine, const Memory *memory,
                              Buffer *out)
{
    size_t metadata_size = 0;
    size_t content_size = memory->content_byte_count;
    size_t start = out->count;
    char header[96];
    int header_size;
    if (content_size > MAX_MEMORY_BLOB_BYTES) return -1;
    append_metadata(out, &memory->metadata);
    metadata_size = out->count - start;
    header_size = snprintf(header, sizeof(header), "%zu %zu\n",
                           metadata_size, content_size);
    if (header_size < 0 || (size_t)header_size >= sizeof(header)) {
        out->count = start;
        return -1;
    }
    if (start > MAX_RESPONSE_BYTES ||
        (size_t)header_size > MAX_RESPONSE_BYTES - start ||
        metadata_size > MAX_RESPONSE_BYTES - start - (size_t)header_size ||
        content_size > MAX_RESPONSE_BYTES - start - (size_t)header_size -
                       metadata_size) {
        out->count = start;
        return RECORD_WIRE_CAPACITY_EXCEEDED;
    }
    buffer_reserve(out, (size_t)header_size + content_size);
    memmove(out->items + start + (size_t)header_size,
            out->items + start, metadata_size);
    memcpy(out->items + start, header, (size_t)header_size);
    out->count += (size_t)header_size;
    if (append_memory_content(engine, memory, out) != 0) {
        out->count = start;
        return -1;
    }
    return 0;
}

static int append_decoded_token_sequence(const Engine *engine, Buffer *out,
                                         const uint64_t *tokens, size_t count)
{
    size_t byte_count = 0;
    for (size_t i = 0; i < count; ++i) {
        uint64_t token_id = tokens[i];
        if (token_id >= engine->token_count ||
            engine->tokens[token_id].segment_size >
                MAX_RESPONSE_BYTES - byte_count)
            return -1;
        byte_count += engine->tokens[token_id].segment_size;
    }
    if (byte_count > MAX_RESPONSE_BYTES - out->count) return -1;
    buffer_reserve(out, byte_count);
    for (size_t i = 0; i < count; ++i) {
        const Token *token = &engine->tokens[tokens[i]];
        buffer_append(out, token->segment, token->segment_size);
    }
    return 0;
}

/*
 * Turn ranked activation into raw cognitive content.  The budget is counted
 * in this store's resident token identities, including separators.  Metadata
 * never enters the stream.  Whole traces are preferred; only when no whole
 * trace fits do we expose a prefix of the strongest trace, cut on a native
 * token boundary.
 */
static int append_activation_context(Engine *engine, Memory **results,
                                     size_t candidate_count,
                                     size_t token_budget, Buffer *out,
                                     size_t *selected_count,
                                     size_t *partial_token_count)
{
    static const unsigned char separator_bytes[] = "\n\n";
    TokenVector separator = {0};
    Memory *first_nonempty = NULL;
    uint64_t winning_span_hits = candidate_count == 0 ? 0 :
                                 results[0]->query_span_hits;
    uint64_t winning_span_bytes = candidate_count == 0 ? 0 :
                                  results[0]->query_span_bytes;
    uint64_t winning_match = candidate_count == 0 ? 0 :
                             results[0]->query_match_score;
    size_t remaining = token_budget;
    size_t selected = 0;
    int rc = -1;

    *selected_count = 0;
    *partial_token_count = 0;
    if (encode_greedy(engine, separator_bytes, sizeof(separator_bytes) - 1,
                      &separator) != 0)
        goto done;

    for (size_t i = 0; i < candidate_count; ++i) {
        Memory *memory = results[i];
        size_t cost;
        size_t start;
        bool duplicate = false;
        if ((winning_span_hits != 0 &&
             (memory->query_span_hits != winning_span_hits ||
              memory->query_span_bytes != winning_span_bytes)) ||
            (winning_span_hits == 0 &&
             memory->query_match_score != winning_match))
            break;
        if (memory->token_count == 0) continue;
        for (size_t j = 0; j < selected; ++j) {
            if (memcmp(memory->content_bytes_digest, results[j]->content_bytes_digest,
                       sizeof(memory->content_bytes_digest)) == 0) {
                duplicate = true;
                break;
            }
        }
        if (duplicate) continue;
        if (first_nonempty == NULL) first_nonempty = memory;
        if (selected != 0 && separator.count > SIZE_MAX - memory->token_count)
            continue;
        cost = memory->token_count + (selected == 0 ? 0 : separator.count);
        if (cost > remaining) continue;
        start = out->count;
        if ((selected != 0 &&
             append_decoded_token_sequence(engine, out, separator.items,
                                           separator.count) != 0) ||
            append_memory_content(engine, memory, out) != 0) {
            out->count = start;
            continue;
        }
        results[selected++] = memory;
        remaining -= cost;
    }

    if (selected == 0 && first_nonempty != NULL) {
        size_t prefix = first_nonempty->token_count < token_budget ?
                        first_nonempty->token_count : token_budget;
        if (append_decoded_token_sequence(engine, out, first_nonempty->tokens,
                                          prefix) != 0)
            goto done;
        results[0] = first_nonempty;
        selected = 1;
        *partial_token_count = prefix;
    }
    *selected_count = selected;
    rc = 0;
done:
    free(separator.items);
    return rc;
}

static int append_records_by_id(Engine *engine, const int64_t *ids,
                                size_t count, Buffer *out)
{
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    buffer_printf(out, "%zu\n", count);
    for (size_t i = 0; i < count; ++i) {
        Memory *memory = memory_find_id(engine, ids[i]);
        if (memory == NULL || append_record_wire(engine, memory, out) != 0)
            goto done;
    }
    rc = 0;
done:
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static void count_memory_read(Engine *engine, Memory *memory)
{
    count_sequence_read(engine, memory->tokens, memory->token_count);
    increment_memory_access(engine, memory);
}

static int fetch_memory_record(Engine *engine, int64_t id, bool counted,
                               Buffer *out)
{
    Memory *memory;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find_id(engine, id);
    if (memory == NULL || !memory->visible) goto done;
    buffer_append_text(out, "1\n");
    if (append_record_wire(engine, memory, out) != 0) goto done;
    if (counted) count_memory_read(engine, memory);
    rc = 0;
done:
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static bool metadata_field_matches(const Bytes *field, const char *filter)
{
    return strcmp(filter, "-") == 0 ||
           (field->size == strlen(filter) &&
            memcmp(field->data, filter, field->size) == 0);
}

static bool activation_tier_matches(const Memory *memory)
{
    return metadata_field_matches(&memory->metadata.tier, "working") ||
           metadata_field_matches(&memory->metadata.tier, "semantic") ||
           metadata_field_matches(&memory->metadata.tier, "procedural");
}

static int list_memory_records(Engine *engine, const char *tier,
                               const char *status, const char *order,
                               bool descending, size_t limit, int64_t after_id,
                               bool counted,
                               Buffer *out)
{
    Memory **records = NULL;
    size_t count = 0;
    size_t capacity;
    size_t start = 0;
    size_t header_start = out->count;
    size_t count_header_size;
    size_t emitted = 0;
    size_t ordered_count;
    Memory **ordered;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    ordered_count = engine->memory_count;
    if (strcmp(tier, "-") != 0 && strcmp(status, "-") != 0) {
        MemoryFilterIndex *index = memory_filter_find(engine, tier, status, false);
        if (index == NULL) ordered_count = 0;
        else {
            ordered_count = index->count;
            ordered = strcmp(order, "updated_at") == 0 ?
                      index->updated_order : index->id_order;
        }
    } else {
        ordered = strcmp(order, "updated_at") == 0 ?
                  engine->memory_updated_order : engine->memory_id_order;
    }
    capacity = limit == 0 || limit > ordered_count ? ordered_count : limit;
    records = xcalloc(capacity, sizeof(*records));
    if (ordered_count == 0) ordered = NULL;
    if (!descending && ordered == engine->memory_id_order && after_id > 0) {
        size_t high = ordered_count;
        while (start < high) {
            size_t middle = start + (high - start) / 2;
            if (ordered[middle]->metadata.id <= after_id) start = middle + 1;
            else high = middle;
        }
    } else if (!descending && strcmp(order, "id") == 0 && after_id > 0) {
        size_t high = ordered_count;
        while (start < high) {
            size_t middle = start + (high - start) / 2;
            if (ordered[middle]->metadata.id <= after_id) start = middle + 1;
            else high = middle;
        }
    }
    for (size_t offset = 0; start + offset < ordered_count && count < capacity;
         ++offset) {
        size_t position = descending ? ordered_count - 1 - offset : start + offset;
        Memory *memory = ordered[position];
        if (memory->visible && memory->metadata.id > after_id &&
            metadata_field_matches(&memory->metadata.tier, tier) &&
            metadata_field_matches(&memory->metadata.status, status))
            records[count++] = memory;
    }
    buffer_printf(out, "%zu\n", count);
    count_header_size = out->count - header_start;
    for (size_t i = 0; i < count; ++i) {
        int appended = append_record_wire(engine, records[i], out);
        if (appended == 0) {
            ++emitted;
            continue;
        }
        if (appended != RECORD_WIRE_CAPACITY_EXCEEDED || limit == 0) goto done;
        if (emitted == 0) {
            /* The requested count's wider header must not exclude a first
             * record that fits with the exact single-record count header. */
            if (count_header_size <= 2) goto done;
            out->count = header_start;
            buffer_append_text(out, "1\n");
            count_header_size = 2;
            if (append_record_wire(engine, records[0], out) != 0) goto done;
            emitted = 1;
        }
        break;
    }
    if (emitted != count) {
        char header[32];
        int size = snprintf(header, sizeof(header), "%zu\n", emitted);
        size_t body_start = header_start + count_header_size;
        if (size < 0 || (size_t)size >= sizeof(header) ||
            (size_t)size > count_header_size) goto done;
        memmove(out->items + header_start + (size_t)size,
                out->items + body_start, out->count - body_start);
        out->count -= count_header_size - (size_t)size;
        memcpy(out->items + header_start, header, (size_t)size);
    }
    if (counted) {
        for (size_t i = 0; i < emitted; ++i) count_memory_read(engine, records[i]);
    }
    rc = 0;
done:
    free(records);
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static int provenance_memory_record(Engine *engine, bool by_memory,
                                    SourceEventKind source_kind,
                                    int64_t source_id, const char *tier,
                                    const char *status, Buffer *out)
{
    Memory **order;
    size_t count;
    size_t low = 0;
    size_t high;
    Memory *match = NULL;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    order = by_memory ? engine->memory_source_memory_order :
                        engine->memory_source_event_order;
    count = by_memory ? engine->memory_source_memory_count :
                        engine->memory_source_event_count;
    high = count;
    while (low < high) {
        size_t middle = low + (high - low) / 2;
        if (memory_source_value(order[middle], by_memory) < source_id)
            low = middle + 1;
        else
            high = middle;
    }
    high = low;
    while (high < count &&
           memory_source_value(order[high], by_memory) == source_id)
        ++high;
    while (high > low) {
        Memory *memory = order[--high];
        if (memory->visible &&
            (by_memory || memory->metadata.source_event_kind == source_kind) &&
            metadata_field_matches(&memory->metadata.tier, tier) &&
            metadata_field_matches(&memory->metadata.status, status)) {
            match = memory;
            break;
        }
    }
    if (match == NULL) {
        buffer_append_text(out, "0\n");
    } else {
        buffer_append_text(out, "1\n");
        if (append_record_wire(engine, match, out) != 0) goto done;
    }
    rc = 0;
done:
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static int count_memory_records(Engine *engine, const char *tier,
                                const char *status, int64_t after_id,
                                Buffer *out)
{
    size_t total = 0;
    pthread_mutex_lock(&engine->mutex);
    for (size_t i = 0; i < engine->memory_filter_index_count; ++i) {
        MemoryFilterIndex *index = &engine->memory_filter_indexes[i];
        size_t low = 0;
        size_t high = index->count;
        if ((strcmp(tier, "-") != 0 && strcmp(index->tier, tier) != 0) ||
            (strcmp(status, "-") != 0 && strcmp(index->status, status) != 0))
            continue;
        while (low < high) {
            size_t middle = low + (high - low) / 2;
            if (index->id_order[middle]->metadata.id <= after_id)
                low = middle + 1;
            else
                high = middle;
        }
        if (index->count - low > SIZE_MAX - total) {
            pthread_mutex_unlock(&engine->mutex);
            return -1;
        }
        total += index->count - low;
    }
    buffer_printf(out, "%zu\n", total);
    pthread_mutex_unlock(&engine->mutex);
    return 0;
}

static int parse_memory_id_body(Engine *engine, const unsigned char *body,
                                size_t body_size, size_t expected,
                                Memory ***records_out)
{
    Memory **records = xcalloc(expected, sizeof(*records));
    size_t offset = 0;
    for (size_t i = 0; i < expected; ++i) {
        const unsigned char *newline = memchr(body + offset, '\n', body_size - offset);
        char text[64];
        size_t length;
        int64_t id;
        if (newline == NULL) goto invalid;
        length = (size_t)(newline - (body + offset));
        if (length == 0 || length >= sizeof(text)) goto invalid;
        memcpy(text, body + offset, length);
        text[length] = '\0';
        if (parse_memory_id(text, &id) != 0) goto invalid;
        records[i] = memory_find_id(engine, id);
        if (records[i] == NULL || !records[i]->visible) goto invalid;
        for (size_t j = 0; j < i; ++j) {
            if (records[j] == records[i]) goto invalid;
        }
        offset += length + 1;
    }
    if (offset != body_size) goto invalid;
    *records_out = records;
    return 0;
invalid:
    free(records);
    return -1;
}

static int batch_memory_records(Engine *engine, const unsigned char *body,
                                size_t body_size, size_t count, bool counted,
                                bool include_records, Buffer *out)
{
    Memory **records = NULL;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    if (parse_memory_id_body(engine, body, body_size, count, &records) != 0)
        goto done;
    if (include_records) {
        buffer_printf(out, "%zu\n", count);
        for (size_t i = 0; i < count; ++i) {
            if (append_record_wire(engine, records[i], out) != 0) goto done;
        }
    } else {
        buffer_printf(out, "%zu\n", count);
    }
    if (counted) {
        for (size_t i = 0; i < count; ++i) count_memory_read(engine, records[i]);
    }
    rc = 0;
done:
    free(records);
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static int get_memory_tokens(Engine *engine, const char *tier, const char *key,
                             Buffer *out)
{
    Memory *memory;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    memory = memory_find(engine, tier, key);
    if (memory == NULL || !memory->visible) goto done;
    if (append_token_sequence(out, memory->tokens, memory->token_count) != 0)
        goto done;
    count_sequence_read(engine, memory->tokens, memory->token_count);
    increment_memory_access(engine, memory);
    rc = 0;
done:
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static uint64_t saturating_add_scaled(uint64_t value, uint64_t add, uint64_t scale)
{
    uint64_t scaled;
    if (add != 0 && scale > UINT64_MAX / add) scaled = UINT64_MAX;
    else scaled = add * scale;
    return UINT64_MAX - value < scaled ? UINT64_MAX : value + scaled;
}

static bool memory_span_matches(const Engine *engine, const Memory *memory,
                                 const MemorySpan *span, const CueSpan *cue)
{
    size_t compared = 0;
    for (size_t i = span->token_index;
         i < memory->token_count && compared < span->size; ++i) {
        const Token *token = &engine->tokens[memory->tokens[i]];
        size_t offset = i == span->token_index ? span->byte_offset : 0;
        size_t count = token->segment_size - offset;
        if (count > span->size - compared) count = span->size - compared;
        if (count != 0 && !folded_bytes_equal(cue->data + compared,
                                              token->segment + offset, count))
            return false;
        compared += count;
    }
    return compared == span->size;
}

static void score_memory_span(const Engine *engine, Memory *memory,
                               const MemorySpan *span, const CueSpan *spans,
                               size_t span_count, bool *matched)
{
    uint64_t hash = span->folded_hash;
    hash ^= hash >> 33;
    hash *= UINT64_C(0xff51afd7ed558ccd);
    hash ^= hash >> 33;
    for (size_t i = 0; i < span_count; ++i) {
        if (!matched[i] && spans[i].size == span->size &&
            spans[i].folded_hash == hash &&
            memory_span_matches(engine, memory, span, &spans[i])) {
            matched[i] = true;
            memory->query_span_hits = saturating_increment(memory->query_span_hits);
            memory->query_span_bytes = saturating_add_scaled(
                memory->query_span_bytes, spans[i].size, 1);
            break;
        }
    }
}

static int score_memory_cue_spans(const Engine *engine, Memory *memory,
                                  const CueSpan *spans, size_t span_count)
{
    bool matched[MAX_CUE_LINK_SOURCES] = {false};
    MemorySpan span = {0};
    size_t decoded_size = 0;

    memory->query_span_hits = 0;
    memory->query_span_bytes = 0;
    if (span_count == 0) return 0;
    /* Preserve whole-sequence validation even if all spans match near its start. */
    for (size_t i = 0; i < memory->token_count; ++i) {
        uint64_t id = memory->tokens[i];
        if (id >= engine->token_count ||
            engine->tokens[id].segment_size > MAX_RESPONSE_BYTES - decoded_size)
            return -1;
        decoded_size += engine->tokens[id].segment_size;
    }
    for (size_t i = 0; i < memory->token_count; ++i) {
        const Token *token = &engine->tokens[memory->tokens[i]];
        for (size_t j = 0; j < token->segment_size; ++j) {
            unsigned char byte = token->segment[j];
            if (cue_span_byte(byte)) {
                if (span.size == 0) {
                    span.token_index = i;
                    span.byte_offset = j;
                    span.folded_hash = UINT64_C(1469598103934665603);
                }
                ++span.size;
                span.folded_hash ^= fold_ascii(byte);
                span.folded_hash *= UINT64_C(1099511628211);
            } else if (span.size != 0) {
                score_memory_span(engine, memory, &span, spans, span_count, matched);
                span.size = 0;
                if (memory->query_span_hits == span_count) return 0;
            }
        }
    }
    if (span.size != 0)
        score_memory_span(engine, memory, &span, spans, span_count, matched);
    return 0;
}

static void score_postings(Engine *engine, const Token *token, uint64_t weight,
                           uint32_t generation, size_t *touched_count,
                           bool direct_match)
{
    for (size_t i = 0; i < token->posting_count; ++i) {
        const Posting *posting = &token->postings[i];
        Memory *memory = posting->memory;
        if (memory->insertion_index < engine->memory_count) {
            if (memory->query_generation != generation) {
                memory->query_generation = generation;
                memory->query_score = 0;
                memory->query_match_score = 0;
                memory->query_span_hits = 0;
                memory->query_span_bytes = 0;
                engine->query_touched[(*touched_count)++] = memory;
            }
            memory->query_score = saturating_add_scaled(memory->query_score,
                                                        posting->occurrences,
                                                        weight);
            if (direct_match)
                memory->query_match_score = saturating_add_scaled(
                    memory->query_match_score, 1, weight);
        }
    }
}

static bool scored_memory_better(const Memory *left, const Memory *right)
{
    if (left->query_span_hits != right->query_span_hits)
        return left->query_span_hits > right->query_span_hits;
    if (left->query_span_bytes != right->query_span_bytes)
        return left->query_span_bytes > right->query_span_bytes;
    if (left->query_match_score != right->query_match_score)
        return left->query_match_score > right->query_match_score;
    if (left->query_score != right->query_score)
        return left->query_score > right->query_score;
    /* Reads measure exposure, not relevance. Equal evidence favors freshness;
     * memory_updated_compare supplies the final deterministic ID tie-break. */
    return memory_updated_compare(left, right) > 0;
}

static bool scored_memory_worse(const Memory *left, const Memory *right)
{
    return scored_memory_better(right, left);
}

static int activation_cohort_compare(const Memory *left, const Memory *right)
{
    if (left->query_span_hits != right->query_span_hits)
        return left->query_span_hits > right->query_span_hits ? 1 : -1;
    if (left->query_span_bytes != right->query_span_bytes)
        return left->query_span_bytes > right->query_span_bytes ? 1 : -1;
    if (left->query_span_hits == 0 &&
        left->query_match_score != right->query_match_score)
        return left->query_match_score > right->query_match_score ? 1 : -1;
    return 0;
}

static void topk_offer(Memory **heap, size_t *count, size_t capacity,
                       Memory *memory)
{
    size_t position;
    if (capacity == 0) return;
    if (*count < capacity) {
        position = (*count)++;
        heap[position] = memory;
        while (position != 0) {
            size_t parent = (position - 1) / 2;
            if (!scored_memory_worse(heap[position], heap[parent])) break;
            {
                Memory *temporary = heap[position];
                heap[position] = heap[parent];
                heap[parent] = temporary;
            }
            position = parent;
        }
        return;
    }
    if (!scored_memory_better(memory, heap[0])) return;
    heap[0] = memory;
    position = 0;
    for (;;) {
        size_t left = position * 2 + 1;
        size_t right = left + 1;
        size_t worst = position;
        if (left < *count && scored_memory_worse(heap[left], heap[worst]))
            worst = left;
        if (right < *count && scored_memory_worse(heap[right], heap[worst]))
            worst = right;
        if (worst == position) break;
        {
            Memory *temporary = heap[position];
            heap[position] = heap[worst];
            heap[worst] = temporary;
        }
        position = worst;
    }
}

static void sort_scored_results(Memory **results, size_t count)
{
    size_t heap_count = count;
    while (heap_count > 1) {
        Memory *temporary = results[0];
        size_t position = 0;
        results[0] = results[heap_count - 1];
        results[heap_count - 1] = temporary;
        --heap_count;
        for (;;) {
            size_t left = position * 2 + 1;
            size_t right = left + 1;
            size_t worst = position;
            if (left < heap_count &&
                scored_memory_worse(results[left], results[worst]))
                worst = left;
            if (right < heap_count &&
                scored_memory_worse(results[right], results[worst]))
                worst = right;
            if (worst == position) break;
            temporary = results[position];
            results[position] = results[worst];
            results[worst] = temporary;
            position = worst;
        }
    }
}

static int collect_cue_constituents(const Engine *engine,
                                    const TokenVector *cue,
                                    TokenVector *constituents)
{
    TokenVector stack = {0};
    if (!posting_expansion_within_budget(engine, cue->items, cue->count)) return -1;
    for (size_t i = 0; i < cue->count; ++i) {
        const Token *root = &engine->tokens[cue->items[i]];
        if (root->has_children) {
            token_vector_push(&stack, root->right);
            token_vector_push(&stack, root->left);
        }
        while (stack.count != 0) {
            uint64_t token_id = stack.items[--stack.count];
            const Token *token = &engine->tokens[token_id];
            if (constituents->count >= MAX_EXPANDED_POSTING_NODES) goto invalid;
            token_vector_push(constituents, token_id);
            if (token->has_children) {
                token_vector_push(&stack, token->right);
                token_vector_push(&stack, token->left);
            }
        }
    }
    free(stack.items);
    if (constituents->count > 1) {
        size_t unique = 1;
        qsort(constituents->items, constituents->count,
              sizeof(*constituents->items), compare_u64);
        for (size_t i = 1; i < constituents->count; ++i) {
            if (constituents->items[i] != constituents->items[unique - 1])
                constituents->items[unique++] = constituents->items[i];
        }
        constituents->count = unique;
    }
    return 0;
invalid:
    free(stack.items);
    free(constituents->items);
    memset(constituents, 0, sizeof(*constituents));
    return -1;
}

static bool frontier_token_hotter(const Engine *engine, uint64_t left,
                                  uint64_t right)
{
    const Token *a = &engine->tokens[left];
    const Token *b = &engine->tokens[right];
    if (a->order_usage_count != b->order_usage_count)
        return a->order_usage_count > b->order_usage_count;
    if (a->order_read_count != b->order_read_count)
        return a->order_read_count > b->order_read_count;
    if (a->order_write_count != b->order_write_count)
        return a->order_write_count > b->order_write_count;
    return left < right;
}

static void frontier_push(const Engine *engine, TokenVector *frontier, uint64_t id)
{
    size_t position;
    token_vector_push(frontier, id);
    position = frontier->count - 1;
    while (position != 0) {
        size_t parent = (position - 1) / 2;
        if (!frontier_token_hotter(engine, frontier->items[position],
                                   frontier->items[parent])) break;
        {
            uint64_t temporary = frontier->items[position];
            frontier->items[position] = frontier->items[parent];
            frontier->items[parent] = temporary;
        }
        position = parent;
    }
}

static uint64_t frontier_pop(const Engine *engine, TokenVector *frontier)
{
    uint64_t result = frontier->items[0];
    --frontier->count;
    if (frontier->count != 0) {
        size_t position = 0;
        frontier->items[0] = frontier->items[frontier->count];
        for (;;) {
            size_t left = position * 2 + 1;
            size_t right = left + 1;
            size_t hottest = position;
            if (left < frontier->count &&
                frontier_token_hotter(engine, frontier->items[left],
                                       frontier->items[hottest])) hottest = left;
            if (right < frontier->count &&
                frontier_token_hotter(engine, frontier->items[right],
                                       frontier->items[hottest])) hottest = right;
            if (hottest == position) break;
            {
                uint64_t temporary = frontier->items[position];
                frontier->items[position] = frontier->items[hottest];
                frontier->items[hottest] = temporary;
            }
            position = hottest;
        }
    }
    return result;
}

static uint32_t next_query_generation(Engine *engine)
{
    ++engine->query_generation;
    if (engine->query_generation == 0) {
        for (size_t i = 0; i < engine->memory_count; ++i)
            engine->memory_order[i]->query_generation = 0;
        engine->query_generation = 1;
    }
    return engine->query_generation;
}

static uint32_t next_query_token_generation(Engine *engine)
{
    if (engine->query_token_mark_capacity < engine->token_count) {
        size_t old_capacity = engine->query_token_mark_capacity;
        engine->query_token_marks = xrealloc(engine->query_token_marks,
            engine->token_count * sizeof(*engine->query_token_marks));
        memset(engine->query_token_marks + old_capacity, 0,
            (engine->token_count - old_capacity) *
                sizeof(*engine->query_token_marks));
        engine->query_token_mark_capacity = engine->token_count;
    }
    ++engine->query_token_generation;
    if (engine->query_token_generation == 0) {
        memset(engine->query_token_marks, 0,
               engine->query_token_mark_capacity *
                   sizeof(*engine->query_token_marks));
        engine->query_token_generation = 1;
    }
    return engine->query_token_generation;
}

static void collect_cue_link_sources(Engine *engine,
                                     const TokenVector *cue,
                                     TokenVector *sources,
                                     size_t *root_source_count)
{
    uint32_t generation = next_query_token_generation(engine);
    TokenVector frontier = {0};
    for (size_t i = 0; i < cue->count; ++i) {
        uint64_t id = cue->items[i];
        if (engine->query_token_marks[id] == generation) continue;
        engine->query_token_marks[id] = generation;
        if (sources->count < MAX_CUE_LINK_SOURCES) token_vector_push(sources, id);
        if (sources->count == MAX_CUE_LINK_SOURCES) break;
    }
    *root_source_count = sources->count;
    for (size_t i = 0; i < *root_source_count; ++i) {
        const Token *root = &engine->tokens[sources->items[i]];
        if (!root->has_children) continue;
        if (engine->query_token_marks[root->left] != generation) {
            engine->query_token_marks[root->left] = generation;
            frontier_push(engine, &frontier, root->left);
        }
        if (engine->query_token_marks[root->right] != generation) {
            engine->query_token_marks[root->right] = generation;
            frontier_push(engine, &frontier, root->right);
        }
    }
    while (frontier.count != 0 && sources->count < MAX_CUE_LINK_SOURCES) {
        uint64_t id = frontier_pop(engine, &frontier);
        const Token *token = &engine->tokens[id];
        token_vector_push(sources, id);
        if (!token->has_children) continue;
        if (engine->query_token_marks[token->left] != generation) {
            engine->query_token_marks[token->left] = generation;
            frontier_push(engine, &frontier, token->left);
        }
        if (engine->query_token_marks[token->right] != generation) {
            engine->query_token_marks[token->right] = generation;
            frontier_push(engine, &frontier, token->right);
        }
    }
    free(frontier.items);
}

static int query_memories(Engine *engine, const unsigned char *cue, size_t cue_size,
                          size_t limit, const char *tier_filter,
                          const char *status_filter, bool full_records,
                          bool count_candidate_reads, bool learn_query,
                          size_t context_token_budget, Buffer *out)
{
    CueSpan cue_spans[MAX_CUE_LINK_SOURCES];
    TokenVector cue_tokens = {0};
    TokenVector cue_constituents = {0};
    TokenVector cue_link_sources = {0};
    size_t touched_count = 0;
    size_t result_count = 0;
    size_t partial_token_count = 0;
    size_t root_link_source_count = 0;
    size_t cue_span_count = context_token_budget == 0 ? 0 :
                            collect_cue_spans(cue, cue_size, cue_spans);
    uint32_t generation;
    int rc = -1;
    pthread_mutex_lock(&engine->mutex);
    if (encode_greedy(engine, cue, cue_size, &cue_tokens) != 0 ||
        collect_cue_constituents(engine, &cue_tokens, &cue_constituents) != 0)
        goto done;
    collect_cue_link_sources(engine, &cue_tokens, &cue_link_sources,
                             &root_link_source_count);
    generation = next_query_generation(engine);
    for (size_t i = 0; i < cue_constituents.count; ++i) {
        const Token *constituent =
            &engine->tokens[cue_constituents.items[i]];
        score_postings(engine, constituent,
                       CUE_CONSTITUENT_WEIGHT,
                       generation, &touched_count, true);
    }
    for (size_t i = 0; i < cue_tokens.count; ++i) {
        Token *source = &engine->tokens[cue_tokens.items[i]];
        score_postings(engine, source, CUE_ROOT_WEIGHT, generation,
                       &touched_count, true);
    }
    for (size_t i = 0; i < cue_link_sources.count; ++i) {
        Token *source = &engine->tokens[cue_link_sources.items[i]];
        size_t traverse = source->link_count < 16 ? source->link_count : 16;
        uint64_t cap = i < root_link_source_count ?
                       CUE_ROOT_WEIGHT : CUE_CONSTITUENT_WEIGHT;
        for (size_t j = 0; j < traverse; ++j) {
            Link *link = &source->links[j];
            uint64_t strength = link_strength(link);
            uint64_t weight = strength > cap ? cap : strength;
            score_postings(engine, &engine->tokens[link->target],
                           weight == 0 ? 1 : weight, generation,
                           &touched_count, false);
        }
    }
    if (context_token_budget != 0 || limit > engine->memory_count)
        limit = engine->memory_count;
    if (engine->query_result_capacity < limit) {
        engine->query_results = xrealloc(engine->query_results,
            limit * sizeof(*engine->query_results));
        engine->query_result_capacity = limit;
    }
    for (size_t i = 0; i < touched_count; ++i) {
        Memory *memory = engine->query_touched[i];
        if (memory->visible &&
            (context_token_budget == 0 || activation_tier_matches(memory)) &&
            (tier_filter == NULL ||
             metadata_field_matches(&memory->metadata.tier, tier_filter)) &&
            (status_filter == NULL ||
             metadata_field_matches(&memory->metadata.status, status_filter))) {
            /* All comparator fields must be final before building the heap. */
            if (context_token_budget != 0) {
                if (score_memory_cue_spans(engine, memory,
                                           cue_spans, cue_span_count) != 0)
                    goto done;
                /* Every retained heap member shares the same packable cohort.
                 * A better cohort replaces it; no member of the winner is capped. */
                if (result_count != 0) {
                    int cohort = activation_cohort_compare(memory, engine->query_results[0]);
                    if (cohort < 0) continue;
                    if (cohort > 0) result_count = 0;
                }
            }
            topk_offer(engine->query_results, &result_count, limit, memory);
        }
    }
    sort_scored_results(engine->query_results, result_count);
    if (context_token_budget != 0) {
        if (append_activation_context(engine, engine->query_results, result_count,
                                      context_token_budget, out, &result_count,
                                      &partial_token_count) != 0)
            goto done;
    } else {
        if (full_records) buffer_printf(out, "%zu\n", result_count);
        for (size_t i = 0; i < result_count; ++i) {
            if (full_records) {
                if (append_record_wire(engine, engine->query_results[i], out) != 0)
                    goto done;
            } else if (append_token_sequence(out, engine->query_results[i]->tokens,
                                             engine->query_results[i]->token_count) != 0) {
                goto done;
            }
        }
    }
    if (learn_query) {
        for (size_t i = 0; i < cue_link_sources.count; ++i) {
            Token *source = &engine->tokens[cue_link_sources.items[i]];
            size_t traverse = source->link_count < 16 ? source->link_count : 16;
            for (size_t j = 0; j < traverse; ++j) {
                increment_link_reinforcement(engine, cue_link_sources.items[i],
                                             &source->links[j]);
            }
        }
        count_sequence_usage(engine, &cue_tokens);
    }
    if (count_candidate_reads) {
        for (size_t i = 0; i < result_count; ++i) {
            size_t read_count = partial_token_count != 0 && i == 0 ?
                                partial_token_count :
                                engine->query_results[i]->token_count;
            count_sequence_read(engine, engine->query_results[i]->tokens,
                                read_count);
            increment_memory_access(engine, engine->query_results[i]);
        }
    }
    rc = 0;
done:
    free(cue_tokens.items);
    free(cue_constituents.items);
    free(cue_link_sources.items);
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static int stats_engine(Engine *engine, Buffer *out)
{
    pthread_mutex_lock(&engine->mutex);
    buffer_printf(out, "tokens %zu\nmemories %zu\nlinks %zu\ndirty %zu\n",
                  engine->token_count, engine->memory_count,
                  engine->association_count,
                  (engine->dirty_count - engine->dirty_head) +
                  (engine->dirty_pair_count - engine->dirty_pair_head) +
                  (engine->dirty_link_count - engine->dirty_link_head) +
                  (engine->dirty_memory_count - engine->dirty_memory_head));
    pthread_mutex_unlock(&engine->mutex);
    return 0;
}

static int flush_engine(Engine *engine)
{
    int rc = 0;
    pthread_mutex_lock(&engine->mutex);
    while (has_dirty_state(engine)) {
        if (flush_dirty_state_locked(engine, SIZE_MAX) != 0) {
            rc = -1;
            break;
        }
    }
    pthread_mutex_unlock(&engine->mutex);
    return rc;
}

static const Engine *show_sort_engine;

static int compare_token_snapshot(const void *left, const void *right)
{
    uint64_t a = *(const uint64_t *)left;
    uint64_t b = *(const uint64_t *)right;
    return token_id_hotter(show_sort_engine, a, b) ? -1 :
           token_id_hotter(show_sort_engine, b, a) ? 1 : 0;
}

static int show_tokens(Engine *engine, size_t limit)
{
    uint64_t *ordered;
    pthread_mutex_lock(&engine->mutex);
    if (limit > engine->token_order_count) limit = engine->token_order_count;
    ordered = xmalloc(engine->token_order_count * sizeof(*ordered));
    memcpy(ordered, engine->token_order,
           engine->token_order_count * sizeof(*ordered));
    show_sort_engine = engine;
    if (engine->token_order_count > 1)
        qsort(ordered, engine->token_order_count,
              sizeof(*ordered), compare_token_snapshot);
    for (size_t i = 0; i < limit; ++i) {
        uint64_t id = ordered[i];
        Token *token = &engine->tokens[id];
        printf("%" PRIu64 " %zu %" PRIu64 " %" PRIu64 " %" PRIu64 "\n",
               id, token->segment_size, token->write_count,
               token->read_count, token->usage_count);
    }
    show_sort_engine = NULL;
    free(ordered);
    pthread_mutex_unlock(&engine->mutex);
    return 0;
}

static Bytes column_bytes(sqlite3_stmt *statement, int column)
{
    const unsigned char *data = sqlite3_column_text(statement, column);
    int size = sqlite3_column_bytes(statement, column);
    return bytes_copy(data == NULL ? (const unsigned char *)"" : data,
                      size < 0 ? 0 : (size_t)size);
}

static int prepare_sqlite_memories(sqlite3 *db, sqlite3_stmt **statement)
{
    sqlite3_stmt *columns = NULL;
    bool has_source_kind = false;
    int step;
    if (sqlite3_prepare_v2(db, "PRAGMA table_info(memories)", -1,
                           &columns, NULL) != SQLITE_OK) return -1;
    while ((step = sqlite3_step(columns)) == SQLITE_ROW) {
        const unsigned char *name = sqlite3_column_text(columns, 1);
        if (name != NULL && strcmp((const char *)name, "source_event_kind") == 0)
            has_source_kind = true;
    }
    sqlite3_finalize(columns);
    if (step != SQLITE_DONE) return -1;
    return sqlite3_prepare_v2(db, has_source_kind ?
        "SELECT id,created_at,updated_at,tier,CAST(content AS BLOB),confidence,status,"
        "source_event_id,source_memory_id,supersedes_id,expires_at,source_event_kind "
        "FROM memories ORDER BY id ASC" :
        "SELECT id,created_at,updated_at,tier,CAST(content AS BLOB),confidence,status,"
        "source_event_id,source_memory_id,supersedes_id,expires_at,NULL "
        "FROM memories ORDER BY id ASC", -1, statement, NULL);
}

static int metadata_from_row(sqlite3_stmt *statement, Metadata *metadata)
{
    sqlite3_int64 id = sqlite3_column_int64(statement, 0);
    if (id <= 0) return -1;
    memset(metadata, 0, sizeof(*metadata));
    metadata->id = (int64_t)id;
    metadata->created_at = column_bytes(statement, 1);
    metadata->updated_at = column_bytes(statement, 2);
    metadata->tier = column_bytes(statement, 3);
    metadata->confidence = sqlite3_column_double(statement, 5);
    metadata->status = column_bytes(statement, 6);
    if (sqlite3_column_type(statement, 7) != SQLITE_NULL) {
        metadata->has_source_event_id = true;
        metadata->source_event_id = sqlite3_column_int64(statement, 7);
    }
    if (sqlite3_column_type(statement, 8) != SQLITE_NULL) {
        metadata->has_source_memory_id = true;
        metadata->source_memory_id = sqlite3_column_int64(statement, 8);
    }
    if (sqlite3_column_type(statement, 9) != SQLITE_NULL) {
        metadata->has_supersedes_id = true;
        metadata->supersedes_id = sqlite3_column_int64(statement, 9);
    }
    if (sqlite3_column_type(statement, 10) != SQLITE_NULL) {
        metadata->has_expires_at = true;
        metadata->expires_at = column_bytes(statement, 10);
    }
    if (sqlite3_column_type(statement, 11) != SQLITE_NULL) {
        const unsigned char *kind = sqlite3_column_text(statement, 11);
        int kind_size = sqlite3_column_bytes(statement, 11);
        if (kind == NULL || kind_size <= 0 ||
            parse_source_event_kind(kind, (size_t)kind_size,
                                     &metadata->source_event_kind) != 0 ||
            !metadata->has_source_event_id || metadata->source_event_id <= 0)
            return -1;
    }
    return safe_name(metadata->tier.data, metadata->tier.size);
}

static int catchup_sqlite(const char *store_path, const char *source_path)
{
    Engine engine;
    sqlite3 *source = NULL;
    sqlite3_stmt *statement = NULL;
    sqlite3_stmt *sequence_statement = NULL;
    sqlite3_stmt *receipt_statement = NULL;
    Memory **initial_memories = NULL;
    bool *seen = NULL;
    uint64_t *initial_counters = NULL;
    size_t initial_memory_count = 0;
    size_t initial_token_count = 0;
    int64_t source_maximum = 0;
    int64_t source_sequence = 0;
    int64_t reserved_maximum = 0;
    int64_t current_sequence = 0;
    size_t compared = 0;
    size_t updated = 0;
    size_t added = 0;
    int step = SQLITE_ERROR;
    int rc = -1;
    bool opened = false;
    bool source_transaction = false;

    if (engine_open(&engine, store_path, false) != 0) goto done;
    opened = true;
    if (refuse_pending_receipts(&engine, "catch-up") != 0) goto done;
    initial_memory_count = engine.memory_count;
    initial_token_count = engine.token_count;
    initial_memories = xcalloc(initial_memory_count, sizeof(*initial_memories));
    seen = xcalloc(initial_memory_count, sizeof(*seen));
    initial_counters = xcalloc(initial_token_count * 3, sizeof(*initial_counters));
    for (size_t i = 0; i < initial_memory_count; ++i) {
        Memory *memory = engine.memory_order[i];
        if (memory->insertion_index >= initial_memory_count ||
            initial_memories[memory->insertion_index] != NULL) goto done;
        initial_memories[memory->insertion_index] = memory;
    }
    for (size_t i = 0; i < initial_token_count; ++i) {
        initial_counters[i * 3] = engine.tokens[i].write_count;
        initial_counters[i * 3 + 1] = engine.tokens[i].read_count;
        initial_counters[i * 3 + 2] = engine.tokens[i].usage_count;
    }
    if (sqlite3_open_v2(source_path, &source, SQLITE_OPEN_READONLY, NULL) != SQLITE_OK ||
        sqlite_exec_checked(source, "BEGIN") != 0) {
        fputs("open SQLite catch-up source or memories table failed\n", stderr);
        goto done;
    }
    source_transaction = true;
    if (prepare_sqlite_memories(source, &statement) != SQLITE_OK) {
        fputs("open SQLite catch-up source or memories table failed\n", stderr);
        goto done;
    }
    while ((step = sqlite3_step(statement)) == SQLITE_ROW) {
        Metadata metadata = {0};
        const unsigned char *content = sqlite3_column_blob(statement, 4);
        int content_bytes = sqlite3_column_bytes(statement, 4);
        Memory *memory;
        const unsigned char *content_bytes_pointer =
            content == NULL ? (const unsigned char *)"" : content;
        if (content_bytes < 0 || metadata_from_row(statement, &metadata) != 0) {
            metadata_free(&metadata);
            goto done;
        }
        if (metadata.id > source_maximum) source_maximum = metadata.id;
        memory = memory_find_id(&engine, metadata.id);
        if (memory == NULL) {
            if (add_record(&engine, &metadata, content_bytes_pointer,
                           (size_t)content_bytes, false, true) != 0) {
                metadata_free(&metadata);
                goto done;
            }
            ++added;
            metadata_free(&metadata);
            continue;
        }
        if (memory->insertion_index < initial_memory_count)
            seen[memory->insertion_index] = true;
        ++compared;
        if (memory->metadata.source_event_kind != SOURCE_EVENT_UNKNOWN &&
            metadata.source_event_kind == SOURCE_EVENT_UNKNOWN) {
            fprintf(stderr, "catch-up would erase typed lineage at memory %" PRId64 "\n",
                    metadata.id);
            metadata_free(&metadata);
            goto done;
        }
        if (record_matches(&engine, metadata.id, &metadata, content_bytes_pointer,
                           (size_t)content_bytes)) {
            metadata_free(&metadata);
            continue;
        }
        if (!bytes_equal(&memory->metadata.tier, &metadata.tier) ||
            !bytes_equal(&memory->metadata.created_at, &metadata.created_at)) {
            fprintf(stderr, "catch-up immutable metadata drift at memory %" PRId64 "\n",
                    metadata.id);
            metadata_free(&metadata);
            goto done;
        }
        if (!memory_content_equals(&engine, memory, content_bytes_pointer,
                                    (size_t)content_bytes)) {
            fprintf(stderr, "catch-up content drift at memory %" PRId64 "\n",
                    metadata.id);
            metadata_free(&metadata);
            goto done;
        }
        if (update_record_metadata(&engine, &metadata, content_bytes_pointer,
                                   (size_t)content_bytes, false) != 0) {
            metadata_free(&metadata);
            goto done;
        }
        metadata_free(&metadata);
        ++updated;
    }
    if (step != SQLITE_DONE) goto done;
    for (size_t i = 0; i < initial_memory_count; ++i) {
        if (!seen[i]) {
            fprintf(stderr, "catch-up source is missing store memory %" PRId64 "\n",
                    initial_memories[i]->metadata.id);
            goto done;
        }
    }
    if (sqlite3_prepare_v2(source,
            "SELECT seq FROM sqlite_sequence WHERE name='memories'",
            -1, &sequence_statement, NULL) == SQLITE_OK &&
        sqlite3_step(sequence_statement) == SQLITE_ROW) {
        source_sequence = sqlite3_column_int64(sequence_statement, 0);
    } else {
        source_sequence = source_maximum;
    }
    if (sqlite_exec_checked(source, "COMMIT") != 0) goto done;
    source_transaction = false;
    if (sqlite3_prepare_v2(engine.db,
            "SELECT COALESCE(MAX(memory_id),0) FROM operation_receipt",
            -1, &receipt_statement, NULL) != SQLITE_OK ||
        sqlite3_step(receipt_statement) != SQLITE_ROW) goto done;
    reserved_maximum = sqlite3_column_int64(receipt_statement, 0);
    if (read_sequence_file(store_path, source_maximum, &current_sequence) != 0)
        goto done;
    if (source_sequence < source_maximum) source_sequence = source_maximum;
    if (source_sequence < reserved_maximum) source_sequence = reserved_maximum;
    if (source_sequence < current_sequence) source_sequence = current_sequence;
    if (write_sequence_file(store_path, source_sequence) != 0) goto done;
    for (size_t i = 0; i < initial_token_count; ++i) {
        if (engine.tokens[i].write_count != initial_counters[i * 3] ||
            engine.tokens[i].read_count != initial_counters[i * 3 + 1] ||
            engine.tokens[i].usage_count != initial_counters[i * 3 + 2]) {
            fprintf(stderr, "catch-up changed counters for existing token %zu\n", i);
            goto done;
        }
    }
    for (size_t i = initial_token_count; i < engine.token_count; ++i) {
        if (engine.tokens[i].write_count != 0 || engine.tokens[i].read_count != 0 ||
            engine.tokens[i].usage_count != 0) {
            fprintf(stderr, "catch-up created a non-neutral token %zu\n", i);
            goto done;
        }
    }
    fprintf(stderr, "catch-up complete: compared=%zu updated=%zu added=%zu "
            "sequence=%" PRId64 " memories=%zu tokens=%zu links=%zu\n",
            compared, updated, added, source_sequence, engine.memory_count,
            engine.token_count, engine.association_count);
    rc = 0;
done:
    sqlite3_finalize(statement);
    sqlite3_finalize(sequence_statement);
    sqlite3_finalize(receipt_statement);
    if (source != NULL) {
        if (source_transaction) sqlite_exec_checked(source, "ROLLBACK");
        sqlite3_close(source);
    }
    free(initial_memories);
    free(seen);
    free(initial_counters);
    if (opened && engine_close(&engine) != 0) rc = -1;
    return rc;
}

static int write_sequence_file(const char *store_path, int64_t sequence)
{
    char *path = path_join(store_path, "sqlite-sequence");
    char *temporary = path_join(store_path, ".sqlite-sequence.pending");
    char text[64];
    int size = snprintf(text, sizeof(text), "%" PRId64 "\n", sequence);
    int rc = -1;
    unlink(temporary);
    if (write_file_sync(temporary, (const unsigned char *)text, (size_t)size) == 0 &&
        rename(temporary, path) == 0 &&
        fsync_directory(store_path) == 0) {
        rc = 0;
    }
    if (rc != 0) unlink(temporary);
    free(path);
    free(temporary);
    return rc;
}

static int read_sequence_file(const char *store_path, int64_t fallback, int64_t *sequence)
{
    char *path = path_join(store_path, "sqlite-sequence");
    unsigned char *bytes = NULL;
    size_t size = 0;
    struct stat st;
    int rc;
    if (lstat(path, &st) != 0) {
        int saved = errno;
        free(path);
        if (saved == ENOENT) {
            *sequence = fallback;
            return 0;
        }
        errno = saved;
        return -1;
    }
    if (!S_ISREG(st.st_mode)) {
        fprintf(stderr, "%s is not a regular sequence file\n", path);
        free(path);
        return -1;
    }
    rc = safe_read_regular(path, &bytes, &size);
    free(path);
    if (rc != 0) return -1;
    if (size == 0 || bytes[size - 1] != '\n' ||
        parse_i64_line(bytes, size - 1, sequence, false) != 0 || *sequence < 0) {
        free(bytes);
        return -1;
    }
    if (*sequence < fallback) *sequence = fallback;
    free(bytes);
    return 0;
}

static int remove_tree(const char *path)
{
    struct stat st;
    if (lstat(path, &st) != 0) return errno == ENOENT ? 0 : -1;
    if (!S_ISDIR(st.st_mode)) return unlink(path);
    {
        DIR *directory = opendir(path);
        struct dirent *entry;
        if (directory == NULL) return -1;
        while ((entry = readdir(directory)) != NULL) {
            char *child;
            int rc;
            if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0)
                continue;
            child = path_join(path, entry->d_name);
            rc = remove_tree(child);
            free(child);
            if (rc != 0) {
                closedir(directory);
                return -1;
            }
        }
        closedir(directory);
    }
    return rmdir(path);
}

static char *parent_path(const char *path)
{
    const char *slash = strrchr(path, '/');
    if (slash == NULL) return xstrdup(".");
    if (slash == path) return xstrdup("/");
    {
        size_t size = (size_t)(slash - path);
        char *parent = xmalloc(size + 1);
        memcpy(parent, path, size);
        parent[size] = '\0';
        return parent;
    }
}

static char *staging_path_for(const char *final_path)
{
    for (unsigned attempt = 0; attempt < 1000; ++attempt) {
        Buffer path = {0};
        struct stat st;
        buffer_printf(&path, "%s.pending-%ld-%u", final_path, (long)getpid(), attempt);
        buffer_append(&path, "", 1);
        if (lstat((const char *)path.items, &st) != 0 && errno == ENOENT)
            return (char *)path.items;
        free(path.items);
    }
    return NULL;
}

static int fsync_regular_path(const char *path)
{
    int fd = open(path, O_RDONLY | O_CLOEXEC);
    int rc;
    if (fd < 0) return -1;
    rc = fsync(fd);
    if (close(fd) != 0 && rc == 0) rc = -1;
    return rc;
}

static int import_sqlite_into(const char *store_path, const char *source_path)
{
    Engine engine;
    sqlite3 *source = NULL;
    sqlite3_stmt *statement = NULL;
    sqlite3_stmt *sequence_statement = NULL;
    int step_rc;
    int64_t source_sequence = 0;
    size_t imported = 0;
    int rc = -1;
    bool opened = false;

    if (initialize_store(store_path) != 0) return -1;
    if (engine_open(&engine, store_path, true) != 0) goto done;
    opened = true;
    if (sqlite3_open_v2(source_path, &source, SQLITE_OPEN_READONLY, NULL) != SQLITE_OK ||
        sqlite_exec_checked(source, "BEGIN") != 0 ||
        prepare_sqlite_memories(source, &statement) != SQLITE_OK) {
        fprintf(stderr, "open/import source: %s\n",
                source == NULL ? "failed" : sqlite3_errmsg(source));
        goto done;
    }
    while ((step_rc = sqlite3_step(statement)) == SQLITE_ROW) {
        Metadata metadata = {0};
        const unsigned char *content = sqlite3_column_blob(statement, 4);
        int content_size = sqlite3_column_bytes(statement, 4);
        if (content_size < 0 || metadata_from_row(statement, &metadata) != 0 ||
            add_record(&engine, &metadata, content, (size_t)content_size,
                       false, true) != 0) {
            metadata_free(&metadata);
            fprintf(stderr, "import stopped after %zu rows\n", imported);
            goto done;
        }
        metadata_free(&metadata);
        ++imported;
        if (imported % 1000 == 0) {
            fprintf(stderr, "imported %zu memories; tokens=%zu\n",
                    imported, engine.token_count);
        }
    }
    if (step_rc != SQLITE_DONE) goto done;
    if (sqlite3_prepare_v2(source,
            "SELECT seq FROM sqlite_sequence WHERE name='memories'",
            -1, &sequence_statement, NULL) == SQLITE_OK &&
        sqlite3_step(sequence_statement) == SQLITE_ROW) {
        source_sequence = sqlite3_column_int64(sequence_statement, 0);
    } else if (engine.memory_count != 0) {
        source_sequence = engine.memory_order[engine.memory_count - 1]->metadata.id;
    }
    if (sqlite_exec_checked(source, "COMMIT") != 0 ||
        write_sequence_file(store_path, source_sequence) != 0 ||
        flush_engine(&engine) != 0) {
        goto done;
    }
    fprintf(stderr, "import complete: memories=%zu tokens=%zu links=%zu\n",
            engine.memory_count, engine.token_count, engine.association_count);
    rc = 0;
done:
    sqlite3_finalize(statement);
    sqlite3_finalize(sequence_statement);
    if (source != NULL) {
        if (rc != 0) sqlite_exec_checked(source, "ROLLBACK");
        sqlite3_close(source);
    }
    if (opened && engine_close(&engine) != 0) rc = -1;
    return rc;
}

static int import_sqlite(const char *store_path, const char *source_path)
{
    char *staging = NULL;
    char *parent = NULL;
    char *catalog = NULL;
    char *sequence = NULL;
    char *memories = NULL;
    struct stat st;
    int rc = -1;
    bool renamed = false;
    if (lstat(store_path, &st) == 0 || errno != ENOENT) {
        fprintf(stderr, "import destination already exists: %s\n", store_path);
        return -1;
    }
    staging = staging_path_for(store_path);
    parent = parent_path(store_path);
    if (staging == NULL || import_sqlite_into(staging, source_path) != 0) goto done;
    catalog = path_join(staging, "catalog.sqlite3");
    sequence = path_join(staging, "sqlite-sequence");
    memories = path_join(staging, "memories");
    if (fsync_regular_path(catalog) != 0 || fsync_regular_path(sequence) != 0 ||
        fsync_directory(memories) != 0 || fsync_directory(staging) != 0 ||
        rename_noreplace(staging, store_path) != 0) {
        fprintf(stderr, "publish imported store: %s\n", strerror(errno));
        goto done;
    }
    renamed = true;
    if (fsync_directory(parent) != 0) {
        fprintf(stderr, "sync imported store publication: %s\n", strerror(errno));
        if (rename(store_path, staging) == 0) {
            renamed = false;
            fsync_directory(parent);
        }
        goto done;
    }
    free(staging);
    staging = NULL;
    rc = 0;
done:
    if (renamed && rc != 0)
        fprintf(stderr, "imported store exists but parent fsync could not be confirmed\n");
    if (staging != NULL && remove_tree(staging) != 0)
        fprintf(stderr, "could not clean failed staging store %s\n", staging);
    free(staging);
    free(parent);
    free(catalog);
    free(sequence);
    free(memories);
    return rc;
}

static int bind_bytes(sqlite3_stmt *statement, int index, const Bytes *bytes)
{
    return sqlite3_bind_text64(statement, index, (const char *)bytes->data,
                               (sqlite3_uint64)bytes->size,
                               SQLITE_TRANSIENT, SQLITE_UTF8);
}

static int export_sqlite_into(const char *store_path, const char *destination)
{
    Engine engine;
    sqlite3 *db = NULL;
    sqlite3_stmt *insert = NULL;
    struct stat st;
    int64_t maximum_id = 0;
    int64_t sequence = 0;
    int rc = -1;
    bool opened = false;

    if (lstat(destination, &st) == 0 || errno != ENOENT) {
        fprintf(stderr, "export destination already exists: %s\n", destination);
        return -1;
    }
    if (engine_open(&engine, store_path, false) != 0) goto done;
    opened = true;
    if (refuse_pending_receipts(&engine, "export") != 0) goto done;
    if (sqlite3_open_v2(destination, &db,
                        SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE, NULL) != SQLITE_OK ||
        sqlite_exec_checked(db, "PRAGMA journal_mode=DELETE") != 0 ||
        sqlite_exec_checked(db, "PRAGMA synchronous=FULL") != 0 ||
        sqlite_exec_checked(db,
            "CREATE TABLE memories ("
            "id INTEGER PRIMARY KEY AUTOINCREMENT,"
            "created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            "updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            "tier TEXT NOT NULL,content TEXT NOT NULL,"
            "confidence REAL NOT NULL,status TEXT NOT NULL,"
            "source_event_id INTEGER NULL DEFAULT NULL,"
            "source_memory_id INTEGER NULL DEFAULT NULL,"
            "supersedes_id INTEGER NULL DEFAULT NULL,"
            "expires_at TEXT NULL DEFAULT NULL,"
            "source_event_kind TEXT NULL DEFAULT NULL "
            "CHECK(source_event_kind IS NULL OR "
            "(source_event_kind IN ('event','sense_event') AND "
            "source_event_id IS NOT NULL AND source_event_id > 0)))") != 0 ||
        sqlite_exec_checked(db,
            "CREATE INDEX memories_tier_status ON memories (tier,status);"
            "CREATE INDEX memories_updated_at ON memories (updated_at);"
            "CREATE INDEX memories_source_event ON memories (source_event_id);"
            "BEGIN IMMEDIATE") != 0 ||
        sqlite3_prepare_v2(db,
            "INSERT INTO memories(id,created_at,updated_at,tier,content,confidence,status,"
            "source_event_id,source_memory_id,supersedes_id,expires_at,source_event_kind)"
            "VALUES(?1,?2,?3,?4,?5,?6,?7,?8,?9,?10,?11,?12)",
            -1, &insert, NULL) != SQLITE_OK) {
        goto done;
    }
    for (size_t i = 0; i < engine.memory_count; ++i) {
        Memory *memory = engine.memory_order[i];
        Metadata *metadata = &memory->metadata;
        TokenVector tokens = {memory->tokens, memory->token_count, memory->token_count};
        unsigned char *content = NULL;
        size_t content_size = 0;
        int step;
        if (decode_tokens_to_bytes(&engine, &tokens, &content, &content_size) != 0)
            goto done;
        sqlite3_bind_int64(insert, 1, metadata->id);
        bind_bytes(insert, 2, &metadata->created_at);
        bind_bytes(insert, 3, &metadata->updated_at);
        bind_bytes(insert, 4, &metadata->tier);
        sqlite3_bind_text64(insert, 5,
                            content_size == 0 ? "" : (const char *)content,
                            (sqlite3_uint64)content_size, SQLITE_TRANSIENT, SQLITE_UTF8);
        sqlite3_bind_double(insert, 6, metadata->confidence);
        bind_bytes(insert, 7, &metadata->status);
        if (metadata->has_source_event_id) sqlite3_bind_int64(insert, 8, metadata->source_event_id);
        else sqlite3_bind_null(insert, 8);
        if (metadata->has_source_memory_id) sqlite3_bind_int64(insert, 9, metadata->source_memory_id);
        else sqlite3_bind_null(insert, 9);
        if (metadata->has_supersedes_id) sqlite3_bind_int64(insert, 10, metadata->supersedes_id);
        else sqlite3_bind_null(insert, 10);
        if (metadata->has_expires_at) bind_bytes(insert, 11, &metadata->expires_at);
        else sqlite3_bind_null(insert, 11);
        if (metadata->source_event_kind == SOURCE_EVENT_UNKNOWN)
            sqlite3_bind_null(insert, 12);
        else
            sqlite3_bind_text(insert, 12,
                metadata->source_event_kind == SOURCE_EVENT_EXECUTIVE ? "event" : "sense_event",
                -1, SQLITE_STATIC);
        step = sqlite3_step(insert);
        free(content);
        if (step != SQLITE_DONE) {
            fprintf(stderr, "export row: %s\n", sqlite3_errmsg(db));
            goto done;
        }
        sqlite3_reset(insert);
        sqlite3_clear_bindings(insert);
        if (metadata->id > maximum_id) maximum_id = metadata->id;
    }
    if (read_sequence_file(store_path, maximum_id, &sequence) != 0) goto done;
    sqlite3_finalize(insert);
    insert = NULL;
    if (sqlite_exec_checked(db,
            "DELETE FROM sqlite_sequence WHERE name='memories'") != 0) goto done;
    if (sqlite3_prepare_v2(db,
            "INSERT INTO sqlite_sequence(name,seq) VALUES('memories',?1)",
            -1, &insert, NULL) != SQLITE_OK ||
        sqlite3_bind_int64(insert, 1, sequence) != SQLITE_OK ||
        sqlite3_step(insert) != SQLITE_DONE) goto done;
    sqlite3_finalize(insert);
    insert = NULL;
    if (sqlite_exec_checked(db, "COMMIT") != 0) goto done;
    fprintf(stderr, "export complete: memories=%zu sequence=%" PRId64 "\n",
            engine.memory_count, sequence);
    rc = 0;
done:
    sqlite3_finalize(insert);
    if (db != NULL) {
        if (rc != 0) sqlite_exec_checked(db, "ROLLBACK");
        sqlite3_close(db);
    }
    if (opened && engine_close(&engine) != 0) rc = -1;
    return rc;
}

static int export_sqlite(const char *store_path, const char *destination)
{
    char *staging = NULL;
    char *parent = NULL;
    char *sidecar = NULL;
    struct stat st;
    int rc = -1;
    bool renamed = false;
    if (lstat(destination, &st) == 0 || errno != ENOENT) {
        fprintf(stderr, "export destination already exists: %s\n", destination);
        return -1;
    }
    staging = staging_path_for(destination);
    parent = parent_path(destination);
    if (staging == NULL || export_sqlite_into(store_path, staging) != 0)
        goto done;
    if (fsync_regular_path(staging) != 0 ||
        rename_noreplace(staging, destination) != 0) {
        fprintf(stderr, "publish exported database: %s\n", strerror(errno));
        goto done;
    }
    renamed = true;
    if (fsync_directory(parent) != 0) {
        fprintf(stderr, "sync exported database publication: %s\n", strerror(errno));
        if (rename(destination, staging) == 0) {
            renamed = false;
            fsync_directory(parent);
        }
        goto done;
    }
    free(staging);
    staging = NULL;
    rc = 0;
done:
    if (renamed && rc != 0)
        fprintf(stderr, "export exists but parent fsync could not be confirmed\n");
    if (staging != NULL) {
        unlink(staging);
        sidecar = path_suffix(staging, "-journal");
        unlink(sidecar);
        free(sidecar);
        sidecar = path_suffix(staging, "-wal");
        unlink(sidecar);
        free(sidecar);
        sidecar = path_suffix(staging, "-shm");
        unlink(sidecar);
    }
    free(sidecar);
    free(staging);
    free(parent);
    return rc;
}

static int read_protocol_line(int fd, char *line, size_t capacity)
{
    size_t used = 0;
    while (used + 1 < capacity) {
        unsigned char byte;
        ssize_t got = read(fd, &byte, 1);
        if (got < 0) {
            if (errno == EINTR) continue;
            return -1;
        }
        if (got == 0) return -1;
        if (byte == '\n') {
            line[used] = '\0';
            return 0;
        }
        if (byte == '\r' || byte == '\0') return -1;
        line[used++] = (char)byte;
    }
    return -1;
}

static size_t split_words(char *line, char **words, size_t capacity)
{
    size_t count = 0;
    char *save = NULL;
    char *word = strtok_r(line, " ", &save);
    while (word != NULL && count < capacity) {
        words[count++] = word;
        word = strtok_r(NULL, " ", &save);
    }
    return word == NULL ? count : capacity + 1;
}

static int parse_size(const char *text, size_t maximum, size_t *value)
{
    char *end = NULL;
    unsigned long long parsed;
    if (*text == '\0' || (*text == '0' && text[1] != '\0')) return -1;
    errno = 0;
    parsed = strtoull(text, &end, 10);
    if (errno != 0 || *end != '\0' || parsed > maximum || parsed > SIZE_MAX) return -1;
    *value = (size_t)parsed;
    return 0;
}

static uint64_t monotonic_milliseconds(void)
{
    struct timespec now;
    if (clock_gettime(CLOCK_MONOTONIC, &now) != 0) return 0;
    return (uint64_t)now.tv_sec * 1000u + (uint64_t)now.tv_nsec / 1000000u;
}

static int daemon_reserve_request(DaemonRuntime *runtime, size_t request_bytes)
{
    int result = 0;
    pthread_mutex_lock(&runtime->queue_mutex);
    if (request_bytes > DAEMON_REQUEST_BUDGET_BYTES - runtime->request_bytes) {
        result = 1;
    } else {
        runtime->request_bytes += request_bytes;
    }
    pthread_mutex_unlock(&runtime->queue_mutex);
    return result;
}

static void daemon_release_request(DaemonRuntime *runtime, size_t request_bytes)
{
    pthread_mutex_lock(&runtime->queue_mutex);
    if (runtime->request_bytes < request_bytes)
        runtime->request_bytes = 0;
    else
        runtime->request_bytes -= request_bytes;
    pthread_mutex_unlock(&runtime->queue_mutex);
}

/* Parse only the bounded header and reserve its declared body before reading it. */
static int daemon_pending_header(DaemonRuntime *runtime, PendingClient *pending,
                                 size_t header_size)
{
    char line[4096];
    char *wire_words[8];
    char **words = wire_words;
    size_t count;
    size_t body_size = 0;
    bool versioned = false;

    if (header_size == 0 || header_size > sizeof(line)) return -1;
    memcpy(line, pending->request, header_size - 1);
    line[header_size - 1] = '\0';
    count = split_words(line, wire_words, 8);
    if (count >= 2 && strcmp(wire_words[0], PROTOCOL_ABI) == 0) {
        versioned = true;
        ++words;
        --count;
    }
    if (count == 4 && strcmp(words[0], "BATCH") == 0) {
        if (parse_size(words[3], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 3 && strcmp(words[0], "OBSERVE") == 0) {
        if (parse_size(words[2], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 3 && strcmp(words[0], "QUERY") == 0) {
        if (parse_size(words[2], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 3 && strcmp(words[0], "RECALL_RECORDS") == 0) {
        if (parse_size(words[2], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 3 && strcmp(words[0], "ACTIVATE") == 0) {
        if (parse_size(words[2], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 5 && strcmp(words[0], "RANK_RECORDS") == 0) {
        if (parse_size(words[4], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    } else if (count == 4 &&
               (strcmp(words[0], "CREATE") == 0 ||
                strcmp(words[0], "UPDATE") == 0)) {
        size_t first;
        size_t second;
        if (parse_size(words[2], MAX_REQUEST_BYTES, &first) == 0 &&
            parse_size(words[3], MAX_REQUEST_BYTES, &second) == 0 &&
            first <= MAX_REQUEST_BYTES - second)
            body_size = first + second;
    } else if (count == 5 && strcmp(words[0], "REPLACE") == 0) {
        size_t first;
        size_t second;
        size_t third;
        if (parse_size(words[2], MAX_REQUEST_BYTES, &first) == 0 &&
            parse_size(words[3], MAX_REQUEST_BYTES, &second) == 0 &&
            parse_size(words[4], MAX_REQUEST_BYTES, &third) == 0 &&
            first <= MAX_REQUEST_BYTES - second &&
            first + second <= MAX_REQUEST_BYTES - third)
            body_size = first + second + third;
    } else if (count == 4 && strcmp(words[0], "ADD") == 0) {
        if (parse_size(words[3], MAX_REQUEST_BYTES, &body_size) != 0) body_size = 0;
    }

    if (daemon_reserve_request(runtime, body_size) != 0) return 1;
    pending->request_reserved = true;
    pending->request_bytes = body_size;
    pending->expected_size = header_size + body_size;
    pending->header_parsed = true;
    pending->close_command = versioned && count == 1 &&
                             strcmp(words[0], "CLOSE") == 0;
    if (pending->request_capacity < pending->expected_size) {
        pending->request_capacity = pending->expected_size;
        pending->request = xrealloc(pending->request,
                                    pending->request_capacity);
    }
    if (pending->request_size >= pending->expected_size)
        pending->complete = true;
    return 0;
}

/*
 * Consume at most one bounded chunk per poll pass. This lets valid 64 MiB
 * bodies progress beyond SO_RCVBUF without giving a slow client a worker.
 */
static int daemon_pending_read(DaemonRuntime *runtime, PendingClient *pending,
                               uint64_t now)
{
    ssize_t got;
    size_t available;
    if (pending->complete) return 0;
    if (pending->request == NULL) {
        pending->request_capacity = 4096;
        pending->request = xmalloc(pending->request_capacity);
    }
    available = pending->header_parsed ?
        pending->expected_size - pending->request_size :
        pending->request_capacity - pending->request_size;
    if (available > 65536) available = 65536;
    got = recv(pending->fd, pending->request + pending->request_size,
               available, MSG_DONTWAIT);
    if (got < 0) {
        if (errno == EINTR || errno == EAGAIN || errno == EWOULDBLOCK) return 0;
        return -1;
    }
    if (got == 0) return -1;
    pending->request_size += (size_t)got;
    (void)now;

    if (!pending->header_parsed) {
        unsigned char *newline = memchr(pending->request, '\n',
                                        pending->request_size);
        if (newline != NULL) {
            size_t header_size = (size_t)(newline - pending->request) + 1;
            int parsed = daemon_pending_header(runtime, pending, header_size);
            if (parsed != 0) return parsed;
        } else if (pending->request_size == pending->request_capacity) {
            /* A worker will return the canonical invalid-header response. */
            pending->header_parsed = true;
            pending->expected_size = pending->request_size;
            pending->complete = true;
        }
    }
    if (pending->header_parsed &&
        pending->request_size >= pending->expected_size)
        pending->complete = true;
    return 0;
}

static int request_reader_line(RequestReader *reader, char *line,
                               size_t capacity)
{
    size_t used = 0;
    while (reader->offset < reader->size && used + 1 < capacity) {
        unsigned char byte = reader->data[reader->offset++];
        if (byte == '\n') {
            line[used] = '\0';
            return 0;
        }
        if (byte == '\r' || byte == '\0') return -1;
        line[used++] = (char)byte;
    }
    return -1;
}

static const unsigned char *request_reader_slice(RequestReader *reader,
                                                 size_t size)
{
    const unsigned char *result;
    if (size > reader->size - reader->offset) return NULL;
    result = reader->data + reader->offset;
    reader->offset += size;
    return result;
}

static void daemon_wake_writer(DaemonRuntime *runtime)
{
    unsigned char byte = 1;
    if (runtime->writer_wake_write < 0) return;
    while (write(runtime->writer_wake_write, &byte, 1) < 0 && errno == EINTR) {
    }
}

static bool daemon_reserve_response(DaemonRuntime *runtime,
                                    size_t reservation, bool priority)
{
    bool reserved = false;
    size_t budget = DAEMON_RESPONSE_BUDGET_BYTES +
                    (priority ? DAEMON_SMALL_RESPONSE_RESERVATION : 0u);
    pthread_mutex_lock(&runtime->response_mutex);
    if (!runtime->response_accepting_done &&
        (!atomic_load_explicit(&runtime->closing, memory_order_acquire) || priority) &&
        runtime->response_bytes <= budget &&
        reservation <= budget - runtime->response_bytes) {
        runtime->response_bytes += reservation;
        reserved = true;
    }
    pthread_mutex_unlock(&runtime->response_mutex);
    return reserved;
}

static void daemon_release_response(DaemonRuntime *runtime, size_t reservation)
{
    pthread_mutex_lock(&runtime->response_mutex);
    if (runtime->response_bytes < reservation)
        runtime->response_bytes = 0;
    else
        runtime->response_bytes -= reservation;
    pthread_mutex_unlock(&runtime->response_mutex);
}

static void daemon_remove_response_locked(DaemonRuntime *runtime, size_t index)
{
    DaemonResponse response = runtime->responses[index];
    close(response.fd);
    free(response.payload);
    if (runtime->response_bytes < response.reservation)
        runtime->response_bytes = 0;
    else
        runtime->response_bytes -= response.reservation;
    runtime->responses[index] = runtime->responses[runtime->response_count - 1];
    --runtime->response_count;
}

static void daemon_drop_nonclose_responses_locked(DaemonRuntime *runtime)
{
    for (size_t i = runtime->response_count; i > 0; --i) {
        if (!runtime->responses[i - 1].close_response)
            daemon_remove_response_locked(runtime, i - 1);
    }
}

static int daemon_write_response(DaemonResponse *response)
{
    for (;;) {
        const unsigned char *data;
        size_t *sent;
        size_t size;
        ssize_t wrote;
        if (response->header_sent < response->header_size) {
            data = response->header;
            sent = &response->header_sent;
            size = response->header_size;
        } else if (response->payload_sent < response->payload_size) {
            data = response->payload;
            sent = &response->payload_sent;
            size = response->payload_size;
        } else {
            return 1;
        }
        wrote = send(response->fd, data + *sent, size - *sent,
                     MSG_DONTWAIT | MSG_NOSIGNAL);
        if (wrote > 0) {
            *sent += (size_t)wrote;
            continue;
        }
        if (wrote < 0 && errno == EINTR) continue;
        if (wrote < 0 && (errno == EAGAIN || errno == EWOULDBLOCK)) return 0;
        return -1;
    }
}

static ssize_t daemon_response_index_locked(DaemonRuntime *runtime, uint64_t id)
{
    for (size_t i = 0; i < runtime->response_count; ++i) {
        if (runtime->responses[i].id == id) return (ssize_t)i;
    }
    return -1;
}

static void daemon_drain_writer_wake(DaemonRuntime *runtime)
{
    unsigned char bytes[64];
    for (;;) {
        ssize_t got = read(runtime->writer_wake_read, bytes, sizeof(bytes));
        if (got > 0) continue;
        if (got < 0 && errno == EINTR) continue;
        break;
    }
}

static void *daemon_response_writer(void *argument)
{
    DaemonRuntime *runtime = argument;
    for (;;) {
        struct pollfd ready[1 + DAEMON_RESPONSE_CAPACITY];
        uint64_t ids[DAEMON_RESPONSE_CAPACITY];
        size_t count;
        int polled;
        uint64_t now;

        pthread_mutex_lock(&runtime->response_mutex);
        if (atomic_load_explicit(&runtime->discard_responses, memory_order_acquire))
            daemon_drop_nonclose_responses_locked(runtime);
        if (runtime->response_count == 0 && runtime->response_accepting_done) {
            pthread_mutex_unlock(&runtime->response_mutex);
            return NULL;
        }
        count = runtime->response_count;
        ready[0] = (struct pollfd){runtime->writer_wake_read, POLLIN, 0};
        for (size_t i = 0; i < count; ++i) {
            ids[i] = runtime->responses[i].id;
            ready[i + 1] = (struct pollfd){runtime->responses[i].fd, POLLOUT, 0};
        }
        pthread_mutex_unlock(&runtime->response_mutex);

        polled = poll(ready, (nfds_t)(count + 1), DAEMON_POLL_INTERVAL_MS);
        if (polled < 0 && errno == EINTR) continue;
        if (polled < 0) {
            pthread_mutex_lock(&runtime->response_mutex);
            while (runtime->response_count != 0)
                daemon_remove_response_locked(runtime, runtime->response_count - 1);
            pthread_mutex_unlock(&runtime->response_mutex);
            atomic_store_explicit(&runtime->writer_failed, true,
                                  memory_order_release);
            atomic_store_explicit(&runtime->discard_responses, true,
                                  memory_order_release);
            atomic_store_explicit(&runtime->closing, true,
                                  memory_order_release);
            return NULL;
        }
        if ((ready[0].revents & POLLIN) != 0) daemon_drain_writer_wake(runtime);

        now = monotonic_milliseconds();
        pthread_mutex_lock(&runtime->response_mutex);
        if (atomic_load_explicit(&runtime->discard_responses, memory_order_acquire))
            daemon_drop_nonclose_responses_locked(runtime);
        for (size_t i = count; i > 0; --i) {
            ssize_t index = daemon_response_index_locked(runtime, ids[i - 1]);
            short events = ready[i].revents;
            int write_result = 0;
            if (index < 0) continue;
            if (now >= runtime->responses[index].deadline_ms ||
                (events & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
                daemon_remove_response_locked(runtime, (size_t)index);
                continue;
            }
            if ((events & POLLOUT) != 0)
                write_result = daemon_write_response(&runtime->responses[index]);
            if (write_result != 0)
                daemon_remove_response_locked(runtime, (size_t)index);
        }
        pthread_mutex_unlock(&runtime->response_mutex);
    }
}

static bool daemon_enqueue_response(DaemonRuntime *runtime, int fd, bool ok,
                                    unsigned char *payload, size_t payload_size,
                                    size_t reservation, bool priority)
{
    DaemonResponse response = {0};
    int header_size;
    bool enqueued = false;
    header_size = snprintf((char *)response.header, sizeof(response.header),
                           "%s %zu\n", ok ? "OK" : "ERR", payload_size);
    if (header_size < 0 || (size_t)header_size >= sizeof(response.header) ||
        payload_size > reservation) return false;
    response.fd = fd;
    response.header_size = (size_t)header_size;
    response.payload = payload;
    response.payload_size = payload_size;
    response.reservation = reservation;
    response.deadline_ms = monotonic_milliseconds() + DAEMON_CLIENT_TIMEOUT_MS;
    response.close_response = priority;

    pthread_mutex_lock(&runtime->response_mutex);
    if (priority && runtime->response_count == DAEMON_RESPONSE_CAPACITY) {
        for (size_t i = runtime->response_count; i > 0; --i) {
            if (!runtime->responses[i - 1].close_response) {
                daemon_remove_response_locked(runtime, i - 1);
                break;
            }
        }
    }
    if (!runtime->response_accepting_done &&
        runtime->response_count < DAEMON_RESPONSE_CAPACITY &&
        (!atomic_load_explicit(&runtime->closing, memory_order_acquire) || priority)) {
        response.id = ++runtime->next_response_id;
        if (response.id == 0) response.id = ++runtime->next_response_id;
        runtime->responses[runtime->response_count++] = response;
        enqueued = true;
    }
    pthread_mutex_unlock(&runtime->response_mutex);
    if (enqueued) daemon_wake_writer(runtime);
    return enqueued;
}

static bool daemon_enqueue_literal_response(DaemonRuntime *runtime, int fd,
                                            bool ok, const char *payload,
                                            bool priority)
{
    size_t size = strlen(payload);
    unsigned char *copy;
    if (size > DAEMON_SMALL_RESPONSE_RESERVATION ||
        !daemon_reserve_response(runtime, DAEMON_SMALL_RESPONSE_RESERVATION,
                                 priority)) return false;
    copy = xmalloc(size);
    memcpy(copy, payload, size);
    if (daemon_enqueue_response(runtime, fd, ok, copy, size,
                                DAEMON_SMALL_RESPONSE_RESERVATION,
                                priority)) return true;
    free(copy);
    daemon_release_response(runtime, DAEMON_SMALL_RESPONSE_RESERVATION);
    return false;
}

static bool daemon_engine_poisoned(DaemonRuntime *runtime)
{
    bool poisoned;
    pthread_mutex_lock(&runtime->engine->mutex);
    poisoned = runtime->engine->poisoned;
    pthread_mutex_unlock(&runtime->engine->mutex);
    return poisoned;
}

static void daemon_begin_close(DaemonRuntime *runtime)
{
    atomic_store_explicit(&runtime->closing, true, memory_order_release);
    daemon_wake_writer(runtime);
}

static bool daemon_command_has_large_response(size_t count, char **words)
{
    if (count == 0) return false;
    return strcmp(words[0], "GET") == 0 ||
           strcmp(words[0], "FETCH") == 0 ||
           strcmp(words[0], "LIST") == 0 ||
           strcmp(words[0], "PAGE") == 0 ||
           strcmp(words[0], "PROVENANCE") == 0 ||
           strcmp(words[0], "PROVENANCE_TYPED") == 0 ||
           strcmp(words[0], "BATCH") == 0 ||
           strcmp(words[0], "QUERY") == 0 ||
           strcmp(words[0], "RECALL_RECORDS") == 0 ||
           strcmp(words[0], "ACTIVATE") == 0 ||
           strcmp(words[0], "RANK_RECORDS") == 0 ||
           strcmp(words[0], "CREATE") == 0 ||
           strcmp(words[0], "UPDATE") == 0 ||
           strcmp(words[0], "REPLACE") == 0;
}

static bool handle_daemon_client(DaemonRuntime *runtime, int client,
                                 const unsigned char *request,
                                 size_t request_size)
{
    Engine *engine = runtime->engine;
    RequestReader reader = {request, request_size, 0};
    char line[4096];
    char *wire_words[8];
    char **words = wire_words;
    size_t count;
    Buffer response = {0};
    const unsigned char *input = NULL;
    size_t input_size = 0;
    bool ok = false;
    bool versioned = false;
    bool admission_locked = false;
    bool close_command = false;
    bool response_enqueued = false;
    size_t response_reservation = 0;
    const char *error = "invalid command\n";

    if (request_reader_line(&reader, line, sizeof(line)) != 0) {
        error = "invalid request header\n";
        goto respond;
    }
    count = split_words(line, wire_words, 8);
    if (count >= 2 && strcmp(wire_words[0], PROTOCOL_ABI) == 0) {
        versioned = true;
        ++words;
        --count;
    }
    if (!versioned && !(count == 3 && strcmp(words[0], "GET") == 0) &&
        !(count == 3 && strcmp(words[0], "QUERY") == 0) &&
        !(count == 4 && strcmp(words[0], "ADD") == 0)) {
        error = "protocol ABI mismatch\n";
        goto respond;
    }
    close_command = versioned && count == 1 && strcmp(words[0], "CLOSE") == 0;
    response_reservation = daemon_command_has_large_response(count, words) ?
        MAX_RESPONSE_BYTES : DAEMON_SMALL_RESPONSE_RESERVATION;
    if (!daemon_reserve_response(runtime, response_reservation, close_command)) {
        response_reservation = DAEMON_SMALL_RESPONSE_RESERVATION;
        if (!daemon_reserve_response(runtime, response_reservation,
                                     close_command)) {
            response_reservation = 0;
            goto respond;
        }
        error = "response byte budget exhausted\n";
        goto respond;
    }
    if (close_command) {
        if (atomic_load_explicit(&runtime->close_client,
                                 memory_order_acquire) != client) {
            bool expected = false;
            if (!atomic_compare_exchange_strong_explicit(
                    &runtime->closing, &expected, true,
                    memory_order_acq_rel, memory_order_acquire)) {
                error = "daemon is closing\n";
                goto respond;
            }
            atomic_store_explicit(&runtime->close_client, client,
                                  memory_order_release);
        }
        if (pthread_rwlock_wrlock(&runtime->admission) != 0) {
            atomic_store_explicit(&runtime->close_flush_failed, true,
                                  memory_order_release);
            error = "close admission barrier failed\n";
            goto respond;
        }
        admission_locked = true;
    } else {
        if (pthread_rwlock_rdlock(&runtime->admission) != 0) {
            error = "request admission failed\n";
            goto respond;
        }
        admission_locked = true;
        if (atomic_load_explicit(&runtime->closing, memory_order_acquire)) {
            error = "daemon is closing\n";
            goto respond;
        }
    }
    if (daemon_engine_poisoned(runtime)) {
        error = "resident state is poisoned; daemon is closing\n";
        daemon_begin_close(runtime);
        goto respond;
    }
    if (versioned && count == 1 && strcmp(words[0], "ABI") == 0) {
        buffer_append_text(&response, PROTOCOL_ABI "\n");
        ok = true;
    } else if (versioned && count == 1 && strcmp(words[0], "CAPABILITIES") == 0) {
        buffer_append_text(&response, PROTOCOL_ABI "\n"
                           "metadata-source-kind-1\n"
                           "provenance-source-kind-1\n");
        ok = true;
    } else if (count == 1 && strcmp(words[0], "STATS") == 0) {
        ok = stats_engine(engine, &response) == 0;
    } else if (count == 1 && strcmp(words[0], "FLUSH") == 0) {
        ok = flush_engine(engine) == 0;
        if (!ok) error = "flush failed\n";
    } else if (count == 1 && strcmp(words[0], "CLOSE") == 0) {
        ok = flush_engine(engine) == 0;
        if (!ok) {
            atomic_store_explicit(&runtime->close_flush_failed, true,
                                  memory_order_release);
            error = "close flush failed\n";
        }
    } else if (count == 3 && strcmp(words[0], "GET") == 0) {
        if (safe_name((const unsigned char *)words[1], strlen(words[1])) == 0 &&
            parse_memory_id(words[2], &(int64_t){0}) == 0 &&
            get_memory_tokens(engine, words[1], words[2], &response) == 0) {
            ok = true;
        } else {
            error = "memory not found\n";
        }
    } else if (count == 3 && strcmp(words[0], "FETCH") == 0) {
        int64_t id;
        bool counted = strcmp(words[1], "COUNTED") == 0;
        if ((counted || strcmp(words[1], "NEUTRAL") == 0) &&
            parse_memory_id(words[2], &id) == 0 &&
            fetch_memory_record(engine, id, counted, &response) == 0) {
            ok = true;
        } else {
            error = "memory not found\n";
        }
    } else if (count == 7 && strcmp(words[0], "LIST") == 0) {
        size_t limit;
        bool descending;
        bool counted = strcmp(words[1], "COUNTED") == 0;
        if ((!counted && strcmp(words[1], "NEUTRAL") != 0) ||
            (strcmp(words[2], "-") != 0 &&
             safe_name((const unsigned char *)words[2], strlen(words[2])) != 0) ||
            (strcmp(words[3], "-") != 0 &&
             safe_name((const unsigned char *)words[3], strlen(words[3])) != 0) ||
            (strcmp(words[4], "id") != 0 &&
             strcmp(words[4], "updated_at") != 0) ||
            (strcmp(words[5], "ASC") != 0 && strcmp(words[5], "DESC") != 0) ||
            parse_size(words[6], 1000000, &limit) != 0) {
            error = "invalid list bounds\n";
            goto respond;
        }
        descending = strcmp(words[5], "DESC") == 0;
        ok = list_memory_records(engine, words[2], words[3], words[4],
                                 descending, limit, 0, counted, &response) == 0;
        if (!ok) error = "list response exceeds bounds\n";
    } else if (count == 6 && strcmp(words[0], "PAGE") == 0) {
        size_t after;
        size_t limit;
        bool counted = strcmp(words[1], "COUNTED") == 0;
        if ((!counted && strcmp(words[1], "NEUTRAL") != 0) ||
            (strcmp(words[2], "-") != 0 &&
             safe_name((const unsigned char *)words[2], strlen(words[2])) != 0) ||
            (strcmp(words[3], "-") != 0 &&
             safe_name((const unsigned char *)words[3], strlen(words[3])) != 0) ||
            parse_size(words[4], INT64_MAX, &after) != 0 ||
            parse_size(words[5], 1000, &limit) != 0 || limit == 0) {
            error = "invalid page bounds\n";
            goto respond;
        }
        ok = list_memory_records(engine, words[2], words[3], "id", false,
                                 limit, (int64_t)after, counted, &response) == 0;
        if (!ok) error = "page response exceeds bounds\n";
    } else if (count == 4 && strcmp(words[0], "COUNT") == 0) {
        size_t after;
        if ((strcmp(words[1], "-") != 0 &&
             safe_name((const unsigned char *)words[1], strlen(words[1])) != 0) ||
            (strcmp(words[2], "-") != 0 &&
             safe_name((const unsigned char *)words[2], strlen(words[2])) != 0) ||
            parse_size(words[3], INT64_MAX, &after) != 0) {
            error = "invalid count bounds\n";
            goto respond;
        }
        ok = count_memory_records(engine, words[1], words[2],
                                  (int64_t)after, &response) == 0;
        if (!ok) error = "count response exceeds bounds\n";
    } else if (count == 5 && (strcmp(words[0], "PROVENANCE") == 0 ||
                            strcmp(words[0], "PROVENANCE_TYPED") == 0)) {
        int64_t source_id;
        bool by_memory = strcmp(words[1], "MEMORY") == 0;
        bool typed_command = strcmp(words[0], "PROVENANCE_TYPED") == 0;
        SourceEventKind source_kind = strcmp(words[1], "SENSE_EVENT") == 0 ?
            SOURCE_EVENT_SENSE : SOURCE_EVENT_EXECUTIVE;
        if ((typed_command && by_memory) ||
            (!by_memory && strcmp(words[1], "EVENT") != 0 &&
             strcmp(words[1], "SENSE_EVENT") != 0) ||
            parse_memory_id(words[2], &source_id) != 0 ||
            (strcmp(words[3], "-") != 0 &&
             safe_name((const unsigned char *)words[3], strlen(words[3])) != 0) ||
            (strcmp(words[4], "-") != 0 &&
             safe_name((const unsigned char *)words[4], strlen(words[4])) != 0)) {
            error = "invalid provenance bounds\n";
            goto respond;
        }
        ok = provenance_memory_record(engine, by_memory, source_kind, source_id,
                                      words[3], words[4], &response) == 0;
        if (!ok) error = "provenance response exceeds bounds\n";
    } else if (count == 4 && strcmp(words[0], "BATCH") == 0) {
        size_t record_count;
        bool counted = strcmp(words[1], "COUNTED") == 0;
        if ((!counted && strcmp(words[1], "NEUTRAL") != 0) ||
            parse_size(words[2], 1000, &record_count) != 0 || record_count == 0 ||
            parse_size(words[3], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid batch bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short batch body\n";
            goto respond;
        }
        ok = batch_memory_records(engine, input, input_size, record_count,
                                  counted, true, &response) == 0;
        if (!ok) error = "invalid batch records\n";
    } else if (count == 3 && strcmp(words[0], "OBSERVE") == 0) {
        size_t record_count;
        if (parse_size(words[1], 1000, &record_count) != 0 || record_count == 0 ||
            parse_size(words[2], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid observe bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short observe body\n";
            goto respond;
        }
        ok = batch_memory_records(engine, input, input_size, record_count,
                                  true, false, &response) == 0;
        if (!ok) error = "invalid observed records\n";
    } else if (count == 3 && strcmp(words[0], "QUERY") == 0) {
        size_t limit;
        if (parse_size(words[1], 1000, &limit) != 0 ||
            parse_size(words[2], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid query bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short query body\n";
            goto respond;
        }
        ok = query_memories(engine, input, input_size, limit, NULL, NULL,
                            false, true, true, 0, &response) == 0;
        if (!ok) error = "query failed\n";
    } else if (count == 3 && strcmp(words[0], "RECALL_RECORDS") == 0) {
        size_t limit;
        if (parse_size(words[1], 1000, &limit) != 0 || limit == 0 ||
            parse_size(words[2], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid recall bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short recall body\n";
            goto respond;
        }
        ok = query_memories(engine, input, input_size, limit, NULL, "active",
                            true, true, true, 0, &response) == 0;
        if (!ok) error = "recall failed\n";
    } else if (count == 3 && strcmp(words[0], "ACTIVATE") == 0) {
        size_t token_budget;
        if (parse_size(words[1], MAX_RESPONSE_BYTES, &token_budget) != 0 ||
            token_budget == 0 ||
            parse_size(words[2], MAX_REQUEST_BYTES, &input_size) != 0 ||
            input_size == 0) {
            error = "invalid activation bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short activation body\n";
            goto respond;
        }
        ok = query_memories(engine, input, input_size, token_budget,
                            NULL, "active", false, true, true,
                            token_budget, &response) == 0;
        if (!ok) error = "activation failed\n";
    } else if (count == 5 && strcmp(words[0], "RANK_RECORDS") == 0) {
        size_t limit;
        if ((strcmp(words[1], "-") != 0 &&
             safe_name((const unsigned char *)words[1], strlen(words[1])) != 0) ||
            (strcmp(words[2], "-") != 0 &&
             safe_name((const unsigned char *)words[2], strlen(words[2])) != 0) ||
            parse_size(words[3], 100, &limit) != 0 || limit == 0 ||
            parse_size(words[4], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid rank bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short rank body\n";
            goto respond;
        }
        ok = query_memories(engine, input, input_size, limit,
                            words[1], words[2], true, false, true,
                            0, &response) == 0;
        if (!ok) error = "rank failed\n";
    } else if (count == 4 &&
               (strcmp(words[0], "CREATE") == 0 ||
                strcmp(words[0], "UPDATE") == 0)) {
        size_t metadata_size;
        size_t content_size;
        Metadata metadata = {0};
        int64_t assigned_id = 0;
        bool create = strcmp(words[0], "CREATE") == 0;
        bool complete = false;
        unsigned char request_digest[32];
        if (!valid_operation_key(words[1]) ||
            parse_size(words[2], MAX_REQUEST_BYTES, &metadata_size) != 0 ||
            parse_size(words[3], MAX_REQUEST_BYTES, &content_size) != 0 ||
            metadata_size > MAX_REQUEST_BYTES - content_size) {
            error = "invalid record bounds\n";
            goto respond;
        }
        input_size = metadata_size + content_size;
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short record body\n";
            goto respond;
        }
        if (metadata_decode(input, metadata_size, &metadata, create) != 0 ||
            (create && metadata.id != 0) || (!create && metadata.id <= 0)) {
            metadata_free(&metadata);
            error = "invalid positional metadata\n";
            goto respond;
        }
        if (!create && episodic_reactivation_requested(engine, &metadata)) {
            metadata_free(&metadata);
            error = "episodic memory status cannot be reactivated\n";
            goto respond;
        }
        operation_request_digest(&metadata, NULL, input + metadata_size,
                                 content_size, request_digest);
        if (receipt_begin(engine, words[1], request_digest,
                          create ? "create" : "update",
                          create ? 0 : metadata.id,
                          &assigned_id, &complete) != 0) {
            metadata_free(&metadata);
            error = "operation receipt conflict\n";
            goto respond;
        }
        if (complete) {
            metadata_free(&metadata);
            ok = append_records_by_id(engine, &assigned_id, 1, &response) == 0;
            if (!ok) error = "receipt response failed\n";
            goto respond;
        }
        if (create) {
            metadata.id = assigned_id;
            ok = record_semantically_matches(engine, assigned_id, &metadata,
                                             input + metadata_size, content_size);
            if (!ok) {
                ok = add_record(engine, &metadata, input + metadata_size,
                                content_size, true, false) == 0;
            }
        } else {
            ok = record_semantically_matches(engine, assigned_id, &metadata,
                                             input + metadata_size, content_size);
            if (!ok) {
                ok = update_record_metadata(engine, &metadata,
                                            input + metadata_size,
                                            content_size, true) == 0;
            }
        }
        if (ok && create) receipt_fault_point("after_create");
        metadata_free(&metadata);
        if (ok && receipt_finish(engine, words[1], assigned_id, create) != 0)
            ok = false;
        if (ok) {
            ok = append_records_by_id(engine, &assigned_id, 1, &response) == 0;
            if (!ok) error = "receipt response failed\n";
        } else {
            error = create ? "create failed\n" : "metadata update failed\n";
        }
    } else if (count == 5 && strcmp(words[0], "REPLACE") == 0) {
        size_t new_metadata_size;
        size_t content_size;
        size_t old_metadata_size;
        Metadata new_metadata = {0};
        Metadata old_metadata = {0};
        int64_t assigned_id = 0;
        int64_t response_ids[2];
        bool complete = false;
        unsigned char request_digest[32];
        unsigned char *old_content = NULL;
        size_t old_content_size = 0;
        if (!valid_operation_key(words[1]) ||
            parse_size(words[2], MAX_REQUEST_BYTES, &new_metadata_size) != 0 ||
            parse_size(words[3], MAX_REQUEST_BYTES, &content_size) != 0 ||
            parse_size(words[4], MAX_REQUEST_BYTES, &old_metadata_size) != 0 ||
            new_metadata_size > MAX_REQUEST_BYTES - content_size ||
            new_metadata_size + content_size > MAX_REQUEST_BYTES - old_metadata_size) {
            error = "invalid replacement bounds\n";
            goto respond;
        }
        input_size = new_metadata_size + content_size + old_metadata_size;
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short replacement body\n";
            goto respond;
        }
        if (metadata_decode(input, new_metadata_size, &new_metadata, true) != 0 ||
            metadata_decode(input + new_metadata_size + content_size,
                            old_metadata_size, &old_metadata, false) != 0 ||
            new_metadata.id != 0 || old_metadata.id <= 0 ||
            !new_metadata.has_supersedes_id ||
            new_metadata.supersedes_id != old_metadata.id ||
            (old_metadata.status.size == 6 &&
             memcmp(old_metadata.status.data, "active", 6) == 0)) {
            metadata_free(&new_metadata);
            metadata_free(&old_metadata);
            error = "invalid positional replacement metadata\n";
            goto respond;
        }
        operation_request_digest(&new_metadata, &old_metadata,
                                 input + new_metadata_size, content_size,
                                 request_digest);
        if (receipt_begin(engine, words[1], request_digest, "replace", 0,
                          &assigned_id, &complete) != 0) {
            metadata_free(&new_metadata);
            metadata_free(&old_metadata);
            error = "operation receipt conflict\n";
            goto respond;
        }
        response_ids[0] = assigned_id;
        response_ids[1] = old_metadata.id;
        if (!complete) {
            new_metadata.id = assigned_id;
            ok = record_semantically_matches(engine, assigned_id, &new_metadata,
                                             input + new_metadata_size,
                                             content_size);
            if (!ok) {
                ok = add_record(engine, &new_metadata,
                                input + new_metadata_size, content_size,
                                true, false) == 0;
            }
            if (ok) receipt_fault_point("after_new");
            if (ok && copy_memory_content(engine, old_metadata.id,
                                          &old_content, &old_content_size) != 0)
                ok = false;
            if (ok && !record_semantically_matches(engine, old_metadata.id,
                                                   &old_metadata, old_content,
                                                   old_content_size)) {
                ok = update_record_metadata(engine, &old_metadata, old_content,
                                            old_content_size, true) == 0;
            }
            if (ok) receipt_fault_point("after_old");
            if (ok && receipt_finish(engine, words[1], assigned_id, true) != 0)
                ok = false;
        } else {
            ok = true;
        }
        metadata_free(&new_metadata);
        metadata_free(&old_metadata);
        free(old_content);
        if (ok) ok = append_records_by_id(engine, response_ids, 2, &response) == 0;
        if (!ok) error = "replacement failed\n";
    } else if (count == 4 && strcmp(words[0], "ADD") == 0) {
        if (safe_name((const unsigned char *)words[1], strlen(words[1])) != 0 ||
            parse_memory_id(words[2], &(int64_t){0}) != 0 ||
            parse_size(words[3], MAX_REQUEST_BYTES, &input_size) != 0) {
            error = "invalid add bounds\n";
            goto respond;
        }
        input = request_reader_slice(&reader, input_size);
        if (input == NULL) {
            error = "short add body\n";
            goto respond;
        }
        ok = add_default_record(engine, words[1], words[2], input, input_size) == 0;
        if (!ok) error = "add failed\n";
    }
respond:
    if (daemon_engine_poisoned(runtime)) {
        ok = false;
        error = "resident state diverged from durable registry; daemon is closing\n";
        daemon_begin_close(runtime);
    }
    if (!ok) {
        free(response.items);
        response.items = NULL;
        response.count = 0;
        buffer_append_text(&response, error);
    }
    if (close_command) {
        atomic_store_explicit(&runtime->discard_responses, true,
                              memory_order_release);
        daemon_wake_writer(runtime);
    }
    if (admission_locked) pthread_rwlock_unlock(&runtime->admission);
    if (response_reservation != 0 && response.count <= response_reservation) {
        response_enqueued = daemon_enqueue_response(
            runtime, client, ok, response.items, response.count,
            response_reservation, close_command
        );
    }
    if (response_enqueued) {
        response.items = NULL;
        response_reservation = 0;
    }
    free(response.items);
    if (response_reservation != 0)
        daemon_release_response(runtime, response_reservation);
    return response_enqueued;
}

static void *daemon_worker(void *argument)
{
    DaemonRuntime *runtime = argument;
    for (;;) {
        DaemonClient admitted;
        pthread_mutex_lock(&runtime->queue_mutex);
        while (runtime->client_count == 0 && !runtime->accepting_done)
            pthread_cond_wait(&runtime->queue_ready, &runtime->queue_mutex);
        if (runtime->client_count == 0 && runtime->accepting_done) {
            pthread_mutex_unlock(&runtime->queue_mutex);
            return NULL;
        }
        admitted = runtime->clients[runtime->client_head];
        runtime->client_head =
            (runtime->client_head + 1) % DAEMON_QUEUE_CAPACITY;
        --runtime->client_count;
        pthread_mutex_unlock(&runtime->queue_mutex);

        bool response_enqueued;
        if (atomic_load_explicit(&runtime->closing, memory_order_acquire) &&
            atomic_load_explicit(&runtime->close_client,
                                 memory_order_acquire) != admitted.fd) {
            response_enqueued = daemon_enqueue_literal_response(
                runtime, admitted.fd, false, "daemon is closing\n", false
            );
        } else {
            response_enqueued = handle_daemon_client(runtime, admitted.fd,
                                                      admitted.request,
                                                      admitted.request_size);
        }
        if (!response_enqueued) close(admitted.fd);
        free(admitted.request);
        daemon_release_request(runtime, admitted.request_bytes);
    }
}

static void daemon_finish_admission(DaemonRuntime *runtime)
{
    pthread_mutex_lock(&runtime->queue_mutex);
    runtime->accepting_done = true;
    pthread_cond_broadcast(&runtime->queue_ready);
    pthread_mutex_unlock(&runtime->queue_mutex);
}

static int daemon_enqueue_client(DaemonRuntime *runtime, int client,
                                 size_t request_bytes, unsigned char *request,
                                 size_t request_size, bool priority)
{
    size_t tail;
    DaemonClient rejected = {-1, 0, NULL, 0};
    int rc = -1;
    pthread_mutex_lock(&runtime->queue_mutex);
    if (!runtime->accepting_done &&
        (!atomic_load_explicit(&runtime->closing, memory_order_acquire) || priority)) {
        if (priority && runtime->client_count == DAEMON_QUEUE_CAPACITY) {
            tail = (runtime->client_head + runtime->client_count - 1) %
                   DAEMON_QUEUE_CAPACITY;
            rejected = runtime->clients[tail];
            --runtime->client_count;
        }
        if (runtime->client_count < DAEMON_QUEUE_CAPACITY) {
            DaemonClient admitted = {client, request_bytes, request,
                                     request_size};
            if (priority) {
                runtime->client_head =
                    (runtime->client_head + DAEMON_QUEUE_CAPACITY - 1) %
                    DAEMON_QUEUE_CAPACITY;
                runtime->clients[runtime->client_head] = admitted;
            } else {
                tail = (runtime->client_head + runtime->client_count) %
                       DAEMON_QUEUE_CAPACITY;
                runtime->clients[tail] = admitted;
            }
            ++runtime->client_count;
            rc = 0;
        } else if (!priority) {
            rc = 1;
        }
        pthread_cond_signal(&runtime->queue_ready);
    }
    pthread_mutex_unlock(&runtime->queue_mutex);
    if (rejected.fd >= 0) {
        if (!daemon_enqueue_literal_response(
                runtime, rejected.fd, false, "daemon is closing\n", false))
            close(rejected.fd);
        free(rejected.request);
        daemon_release_request(runtime, rejected.request_bytes);
    }
    return rc;
}

static void daemon_join_workers(DaemonRuntime *runtime)
{
    size_t i;
    for (i = 0; i < runtime->worker_count; ++i)
        pthread_join(runtime->workers[i], NULL);
    runtime->worker_count = 0;
}

static void daemon_finish_responses(DaemonRuntime *runtime)
{
    pthread_mutex_lock(&runtime->response_mutex);
    runtime->response_accepting_done = true;
    atomic_store_explicit(&runtime->discard_responses, true,
                          memory_order_release);
    pthread_mutex_unlock(&runtime->response_mutex);
    daemon_wake_writer(runtime);
}

static void daemon_join_writer(DaemonRuntime *runtime)
{
    if (!runtime->writer_started) return;
    pthread_join(runtime->writer, NULL);
    runtime->writer_started = false;
}

static void daemon_runtime_destroy(DaemonRuntime *runtime)
{
    if (runtime->writer_wake_read >= 0) close(runtime->writer_wake_read);
    if (runtime->writer_wake_write >= 0) close(runtime->writer_wake_write);
    pthread_mutex_destroy(&runtime->response_mutex);
    pthread_rwlock_destroy(&runtime->admission);
    pthread_cond_destroy(&runtime->queue_ready);
    pthread_mutex_destroy(&runtime->queue_mutex);
}

static int daemon_runtime_init(DaemonRuntime *runtime, Engine *engine)
{
    size_t i;
    int wake[2];
    memset(runtime, 0, sizeof(*runtime));
    runtime->engine = engine;
    runtime->writer_wake_read = -1;
    runtime->writer_wake_write = -1;
    atomic_init(&runtime->closing, false);
    atomic_init(&runtime->discard_responses, false);
    atomic_init(&runtime->writer_failed, false);
    atomic_init(&runtime->close_flush_failed, false);
    atomic_init(&runtime->close_client, -1);
    if (pthread_mutex_init(&runtime->queue_mutex, NULL) != 0) return -1;
    if (pthread_cond_init(&runtime->queue_ready, NULL) != 0) {
        pthread_mutex_destroy(&runtime->queue_mutex);
        return -1;
    }
    if (pthread_rwlock_init(&runtime->admission, NULL) != 0) {
        pthread_cond_destroy(&runtime->queue_ready);
        pthread_mutex_destroy(&runtime->queue_mutex);
        return -1;
    }
    if (pthread_mutex_init(&runtime->response_mutex, NULL) != 0) {
        pthread_rwlock_destroy(&runtime->admission);
        pthread_cond_destroy(&runtime->queue_ready);
        pthread_mutex_destroy(&runtime->queue_mutex);
        return -1;
    }
    if (pipe2(wake, O_NONBLOCK | O_CLOEXEC) != 0) {
        pthread_mutex_destroy(&runtime->response_mutex);
        pthread_rwlock_destroy(&runtime->admission);
        pthread_cond_destroy(&runtime->queue_ready);
        pthread_mutex_destroy(&runtime->queue_mutex);
        return -1;
    }
    runtime->writer_wake_read = wake[0];
    runtime->writer_wake_write = wake[1];
    if (pthread_create(&runtime->writer, NULL,
                       daemon_response_writer, runtime) != 0) {
        daemon_runtime_destroy(runtime);
        return -1;
    }
    runtime->writer_started = true;
    for (i = 0; i < DAEMON_WORKER_COUNT; ++i) {
        if (pthread_create(&runtime->workers[i], NULL,
                           daemon_worker, runtime) != 0) {
            daemon_finish_admission(runtime);
            daemon_join_workers(runtime);
            daemon_finish_responses(runtime);
            daemon_join_writer(runtime);
            daemon_runtime_destroy(runtime);
            return -1;
        }
        ++runtime->worker_count;
    }
    return 0;
}

static void daemon_signal(int signal_number)
{
    (void)signal_number;
    daemon_stop = 1;
}

static int run_daemon(const char *store_path, const char *requested_socket)
{
    Engine engine;
    DaemonRuntime runtime;
    char *default_socket = NULL;
    const char *socket_path = requested_socket;
    struct sockaddr_un address;
    struct sigaction action;
    struct stat st;
    int server = -1;
    int rc = -1;
    bool opened = false;
    bool runtime_ready = false;
    bool socket_bound = false;
    PendingClient pending[DAEMON_PENDING_TOTAL];
    size_t pending_count = 0;

    if (socket_path == NULL) {
        default_socket = path_join(store_path, "tokmem.sock");
        socket_path = default_socket;
    }
    if (strlen(socket_path) >= sizeof(address.sun_path)) {
        fputs("socket path is too long\n", stderr);
        goto done;
    }
    if (lstat(socket_path, &st) == 0 || errno != ENOENT) {
        fprintf(stderr, "socket path already exists: %s\n", socket_path);
        goto done;
    }
    if (engine_open(&engine, store_path, true) != 0) goto done;
    opened = true;
    server = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (server < 0) goto done;
    memset(&address, 0, sizeof(address));
    address.sun_family = AF_UNIX;
    memcpy(address.sun_path, socket_path, strlen(socket_path) + 1);
    if (bind(server, (struct sockaddr *)&address, sizeof(address)) != 0) {
        fprintf(stderr, "start daemon socket: %s\n", strerror(errno));
        goto done;
    }
    socket_bound = true;
    if (chmod(socket_path, 0600) != 0 ||
        listen(server, (int)DAEMON_PENDING_TOTAL) != 0) {
        fprintf(stderr, "start daemon socket: %s\n", strerror(errno));
        goto done;
    }
    {
        int flags = fcntl(server, F_GETFL, 0);
        if (flags < 0 || fcntl(server, F_SETFL, flags | O_NONBLOCK) != 0) {
            fprintf(stderr, "start daemon socket: %s\n", strerror(errno));
            goto done;
        }
    }
    if (daemon_runtime_init(&runtime, &engine) != 0) {
        fputs("start daemon workers: unavailable\n", stderr);
        goto done;
    }
    runtime_ready = true;
    memset(&action, 0, sizeof(action));
    action.sa_handler = daemon_signal;
    sigemptyset(&action.sa_mask);
    sigaction(SIGINT, &action, NULL);
    sigaction(SIGTERM, &action, NULL);
    signal(SIGPIPE, SIG_IGN);
    daemon_stop = 0;
    fprintf(stderr, "resident daemon ready: %s (%zu tokens, %zu memories)\n",
            socket_path, engine.token_count, engine.memory_count);
    while (!daemon_stop &&
           !atomic_load_explicit(&runtime.closing, memory_order_acquire)) {
        struct pollfd ready[1 + DAEMON_PENDING_TOTAL];
        uint64_t now;
        size_t polled_pending_count = pending_count;
        int polled;
        ready[0] = (struct pollfd){server, POLLIN, 0};
        for (size_t i = 0; i < polled_pending_count; ++i)
            ready[i + 1] = (struct pollfd){pending[i].fd, POLLIN, 0};
        polled = poll(ready, (nfds_t)(polled_pending_count + 1),
                      DAEMON_POLL_INTERVAL_MS);
        if (polled < 0) {
            if (errno == EINTR) continue;
            fprintf(stderr, "accept poll: %s\n", strerror(errno));
            goto done;
        }
        if ((ready[0].revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            fputs("accept poll: listener failed\n", stderr);
            goto done;
        }
        while ((ready[0].revents & POLLIN) != 0 && !daemon_stop &&
               !atomic_load_explicit(&runtime.closing, memory_order_acquire)) {
            int client = accept(server, NULL, NULL);
            if (client < 0) {
                if (errno == EINTR) continue;
                if (errno == EAGAIN || errno == EWOULDBLOCK) break;
                fprintf(stderr, "accept: %s\n", strerror(errno));
                goto done;
            }
            fcntl(client, F_SETFD, FD_CLOEXEC);
            {
                int flags = fcntl(client, F_GETFL, 0);
                struct timeval timeout = {5, 0};
                if (flags < 0 ||
                    fcntl(client, F_SETFL, flags | O_NONBLOCK) != 0) {
                    close(client);
                    continue;
                }
                setsockopt(client, SOL_SOCKET, SO_RCVTIMEO,
                           &timeout, sizeof(timeout));
                setsockopt(client, SOL_SOCKET, SO_SNDTIMEO,
                           &timeout, sizeof(timeout));
            }
            if (pending_count == DAEMON_PENDING_TOTAL) {
                close(client);
            } else {
                PendingClient admitted = {0};
                admitted.fd = client;
                admitted.deadline_ms = monotonic_milliseconds() +
                    (pending_count >= DAEMON_PENDING_CAPACITY ?
                     DAEMON_FAST_LANE_TIMEOUT_MS :
                     DAEMON_CLIENT_TIMEOUT_MS);
                pending[pending_count++] = admitted;
            }
        }
        now = monotonic_milliseconds();
        for (size_t i = polled_pending_count; i > 0; --i) {
            size_t index = i - 1;
            bool remove = false;
            int client = pending[index].fd;
            short events = ready[index + 1].revents;
            if ((events & (POLLERR | POLLHUP | POLLNVAL)) != 0 ||
                now >= pending[index].deadline_ms) {
                remove = true;
            } else if (!pending[index].complete && (events & POLLIN) != 0) {
                int read_result = daemon_pending_read(&runtime,
                                                      &pending[index], now);
                if (read_result != 0) {
                    if (read_result > 0) {
                        if (daemon_enqueue_literal_response(
                                &runtime, client, false,
                                "request byte budget exhausted\n", false))
                            client = -1;
                    }
                    remove = true;
                }
            }
            if (!remove && pending[index].complete) {
                if (fcntl(client, F_GETFL, 0) < 0) {
                    remove = true;
                } else if (pending[index].close_command) {
                        int expected = -1;
                        if (atomic_compare_exchange_strong_explicit(
                                &runtime.close_client, &expected, client,
                                memory_order_acq_rel, memory_order_acquire)) {
                            daemon_begin_close(&runtime);
                            if (daemon_enqueue_client(
                                    &runtime, client,
                                    pending[index].request_bytes,
                                    pending[index].request,
                                    pending[index].expected_size,
                                    true) != 0)
                                close(client);
                            else {
                                pending[index].request = NULL;
                                pending[index].request_reserved = false;
                            }
                        } else {
                            if (!daemon_enqueue_literal_response(
                                    &runtime, client, false,
                                    "daemon is closing\n", false))
                                close(client);
                        }
                        client = -1;
                        remove = true;
                    } else {
                        int enqueued = daemon_enqueue_client(
                            &runtime, client,
                            pending[index].request_bytes,
                            pending[index].request,
                            pending[index].expected_size,
                            false);
                        if (enqueued == 0) {
                            pending[index].request = NULL;
                            pending[index].request_reserved = false;
                            client = -1;
                            remove = true;
                        } else if (enqueued < 0) {
                            close(client);
                            client = -1;
                            remove = true;
                        }
                    }
            }
            if (remove) {
                if (client >= 0) close(client);
                if (pending[index].request_reserved)
                    daemon_release_request(&runtime,
                                           pending[index].request_bytes);
                free(pending[index].request);
                pending[index] = pending[pending_count - 1];
                --pending_count;
            }
        }
    }
    rc = 0;
done:
    if (runtime_ready) {
        bool poisoned;
        daemon_begin_close(&runtime);
        for (size_t i = 0; i < pending_count; ++i) {
            close(pending[i].fd);
            if (pending[i].request_reserved)
                daemon_release_request(&runtime, pending[i].request_bytes);
            free(pending[i].request);
        }
        pending_count = 0;
        daemon_finish_admission(&runtime);
        if (server >= 0) {
            close(server);
            server = -1;
        }
        daemon_join_workers(&runtime);
        daemon_finish_responses(&runtime);
        daemon_join_writer(&runtime);
        poisoned = daemon_engine_poisoned(&runtime);
        if (poisoned || atomic_load_explicit(&runtime.close_flush_failed,
                                             memory_order_acquire) ||
            atomic_load_explicit(&runtime.writer_failed,
                                 memory_order_acquire))
            rc = -1;
        daemon_runtime_destroy(&runtime);
    }
    if (server >= 0) close(server);
    if (socket_bound && socket_path != NULL &&
        lstat(socket_path, &st) == 0 && S_ISSOCK(st.st_mode))
        unlink(socket_path);
    if (opened && engine_close(&engine) != 0) rc = -1;
    free(default_socket);
    return rc;
}

static int connect_unix(const char *socket_path)
{
    struct sockaddr_un address;
    int fd;
    if (strlen(socket_path) >= sizeof(address.sun_path)) return -1;
    fd = socket(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0);
    if (fd < 0) return -1;
    memset(&address, 0, sizeof(address));
    address.sun_family = AF_UNIX;
    memcpy(address.sun_path, socket_path, strlen(socket_path) + 1);
    if (connect(fd, (struct sockaddr *)&address, sizeof(address)) != 0) {
        close(fd);
        return -1;
    }
    {
        struct timeval timeout = {5, 0};
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, sizeof(timeout));
        setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &timeout, sizeof(timeout));
    }
    return fd;
}

static int client_exchange(const char *socket_path, const char *header,
                           const unsigned char *body, size_t body_size)
{
    int fd = connect_unix(socket_path);
    char response_header[128];
    char *words[3];
    size_t word_count;
    size_t response_size;
    unsigned char *response;
    bool ok;
    if (fd < 0) {
        fprintf(stderr, "connect %s: %s\n", socket_path, strerror(errno));
        return -1;
    }
    if (write_all(fd, header, strlen(header)) != 0 ||
        write_all(fd, body, body_size) != 0 ||
        read_protocol_line(fd, response_header, sizeof(response_header)) != 0) {
        close(fd);
        return -1;
    }
    word_count = split_words(response_header, words, 3);
    if (word_count != 2 ||
        parse_size(words[1], MAX_RESPONSE_BYTES, &response_size) != 0 ||
        (strcmp(words[0], "OK") != 0 && strcmp(words[0], "ERR") != 0)) {
        close(fd);
        return -1;
    }
    ok = strcmp(words[0], "OK") == 0;
    response = xmalloc(response_size);
    if (read_exact(fd, response, response_size) != 0) {
        free(response);
        close(fd);
        return -1;
    }
    close(fd);
    if (write_all(ok ? STDOUT_FILENO : STDERR_FILENO, response, response_size) != 0)
        ok = false;
    free(response);
    return ok ? 0 : -1;
}

static int run_client(int argc, char **argv)
{
    const char *socket_path = argv[2];
    Buffer header = {0};
    unsigned char *body = NULL;
    unsigned char *second_body = NULL;
    unsigned char *third_body = NULL;
    size_t body_size = 0;
    size_t second_body_size = 0;
    size_t third_body_size = 0;
    int rc = -1;
    if (argc == 4 && strcmp(argv[3], "capabilities") == 0)
        return client_exchange(socket_path, PROTOCOL_ABI " CAPABILITIES\n", NULL, 0);
    if (argc == 4 && (strcmp(argv[3], "stats") == 0 ||
                      strcmp(argv[3], "flush") == 0 ||
                      strcmp(argv[3], "close") == 0)) {
        const char *command = strcmp(argv[3], "stats") == 0 ?
                              PROTOCOL_ABI " STATS\n" :
                              strcmp(argv[3], "flush") == 0 ?
                              PROTOCOL_ABI " FLUSH\n" : PROTOCOL_ABI " CLOSE\n";
        return client_exchange(socket_path, command, NULL, 0);
    }
    if (argc == 6 && strcmp(argv[3], "get") == 0) {
        buffer_printf(&header, "GET %s %s\n", argv[4], argv[5]);
    } else if (argc == 5 && strcmp(argv[3], "fetch") == 0) {
        buffer_printf(&header, PROTOCOL_ABI " FETCH COUNTED %s\n", argv[4]);
    } else if (argc == 9 && strcmp(argv[3], "list") == 0) {
        buffer_printf(&header, PROTOCOL_ABI " LIST NEUTRAL %s %s %s %s %s\n", argv[4], argv[5],
                      argv[6], argv[7], argv[8]);
    } else if ((argc == 5 || argc == 6) && strcmp(argv[3], "query") == 0) {
        size_t limit = 10;
        if (argc == 6 && parse_size(argv[5], 1000, &limit) != 0) goto done;
        if (read_input_capped(argv[4], MAX_REQUEST_BYTES, &body, &body_size) != 0)
            goto done;
        buffer_printf(&header, "QUERY %zu %zu\n", limit, body_size);
    } else if ((argc == 5 || argc == 6) &&
               strcmp(argv[3], "recall-records") == 0) {
        size_t limit = 10;
        if (argc == 6 && parse_size(argv[5], 1000, &limit) != 0) goto done;
        if (read_input_capped(argv[4], MAX_REQUEST_BYTES, &body, &body_size) != 0)
            goto done;
        buffer_printf(&header, PROTOCOL_ABI " RECALL_RECORDS %zu %zu\n", limit, body_size);
    } else if (argc == 6 && strcmp(argv[3], "activate") == 0) {
        size_t token_budget;
        if (parse_size(argv[5], MAX_RESPONSE_BYTES, &token_budget) != 0 ||
            token_budget == 0 ||
            read_input_capped(argv[4], MAX_REQUEST_BYTES, &body, &body_size) != 0 ||
            body_size == 0)
            goto done;
        buffer_printf(&header, PROTOCOL_ABI " ACTIVATE %zu %zu\n",
                      token_budget, body_size);
    } else if (argc == 7 && strcmp(argv[3], "add") == 0) {
        if (read_input_capped(argv[6], MAX_REQUEST_BYTES, &body, &body_size) != 0)
            goto done;
        buffer_printf(&header, "ADD %s %s %zu\n", argv[4], argv[5], body_size);
    } else if (argc == 7 &&
               (strcmp(argv[3], "create") == 0 ||
                strcmp(argv[3], "update") == 0)) {
        size_t metadata_size;
        if (!valid_operation_key(argv[4]) ||
            read_input_capped(argv[5], MAX_REQUEST_BYTES,
                              &body, &body_size) != 0 ||
            read_input_capped(argv[6], MAX_REQUEST_BYTES,
                              &second_body, &second_body_size) != 0 ||
            body_size > MAX_REQUEST_BYTES - second_body_size) goto done;
        metadata_size = body_size;
        body = xrealloc(body, body_size + second_body_size);
        memcpy(body + body_size, second_body, second_body_size);
        body_size += second_body_size;
        buffer_printf(&header, PROTOCOL_ABI " %s %s %zu %zu\n",
                      strcmp(argv[3], "create") == 0 ? "CREATE" : "UPDATE",
                      argv[4], metadata_size, second_body_size);
    } else if (argc == 8 && strcmp(argv[3], "replace") == 0) {
        size_t new_metadata_size;
        size_t content_size;
        if (!valid_operation_key(argv[4]) ||
            read_input_capped(argv[5], MAX_REQUEST_BYTES, &body, &body_size) != 0 ||
            read_input_capped(argv[6], MAX_REQUEST_BYTES,
                              &second_body, &second_body_size) != 0 ||
            read_input_capped(argv[7], MAX_REQUEST_BYTES,
                              &third_body, &third_body_size) != 0 ||
            body_size > MAX_REQUEST_BYTES - second_body_size ||
            body_size + second_body_size > MAX_REQUEST_BYTES - third_body_size)
            goto done;
        new_metadata_size = body_size;
        content_size = second_body_size;
        body = xrealloc(body, body_size + second_body_size + third_body_size);
        memcpy(body + body_size, second_body, second_body_size);
        memcpy(body + body_size + second_body_size, third_body, third_body_size);
        body_size += second_body_size + third_body_size;
        buffer_printf(&header, PROTOCOL_ABI " REPLACE %s %zu %zu %zu\n",
                      argv[4], new_metadata_size, content_size, third_body_size);
    } else {
        usage(stderr);
        goto done;
    }
    rc = client_exchange(socket_path, (const char *)header.items, body, body_size);
done:
    free(header.items);
    free(body);
    free(second_body);
    free(third_body);
    return rc;
}

int main(int argc, char **argv)
{
    Engine engine;
    size_t limit = 32;
    int rc = -1;
    bool opened = false;
    umask(0077);
    if (argc < 2) {
        usage(stderr);
        return EXIT_FAILURE;
    }
    if (strcmp(argv[1], "init") == 0 && argc == 3) {
        rc = initialize_store(argv[2]);
    } else if (strcmp(argv[1], "import") == 0 && (argc == 4 || argc == 5)) {
        bool serve = argc == 5 && strcmp(argv[4], "--daemon") == 0;
        if (argc == 5 && !serve) {
            usage(stderr);
        } else if (import_sqlite(argv[2], argv[3]) == 0) {
            rc = serve ? run_daemon(argv[2], NULL) : 0;
        }
    } else if (strcmp(argv[1], "export") == 0 && argc == 4) {
        rc = export_sqlite(argv[2], argv[3]);
    } else if (strcmp(argv[1], "catchup") == 0 && argc == 4) {
        rc = catchup_sqlite(argv[2], argv[3]);
    } else if (strcmp(argv[1], "daemon") == 0 && (argc == 3 || argc == 4)) {
        rc = run_daemon(argv[2], argc == 4 ? argv[3] : NULL);
    } else if (strcmp(argv[1], "verify") == 0 && argc == 3) {
        if (engine_open(&engine, argv[2], false) == 0) {
            opened = true;
            fprintf(stdout, "verified tokens=%zu memories=%zu links=%zu\n",
                    engine.token_count, engine.memory_count, engine.association_count);
            rc = 0;
        }
    } else if (strcmp(argv[1], "tokens") == 0 && (argc == 3 || argc == 4)) {
        if (argc == 4 && parse_size(argv[3], SIZE_MAX, &limit) != 0) {
            fputs("invalid limit\n", stderr);
        } else if (engine_open(&engine, argv[2], false) == 0) {
            opened = true;
            rc = show_tokens(&engine, limit);
        }
    } else if (strcmp(argv[1], "client") == 0 && argc >= 4) {
        rc = run_client(argc, argv);
    } else {
        usage(stderr);
    }
    if (opened && engine_close(&engine) != 0) rc = -1;
    return rc == 0 ? EXIT_SUCCESS : EXIT_FAILURE;
}
