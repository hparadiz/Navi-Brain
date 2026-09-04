CC ?= cc

CFLAGS ?= -O2 -g
CFLAGS += -std=c17 -Wall -Wextra -Wpedantic
LDLIBS += -lsqlite3 -pthread

TARGET := build/tokmem
SOURCES := src/tokmem.c

.PHONY: all clean

all: $(TARGET)

$(TARGET): $(SOURCES) | build
	$(CC) $(CPPFLAGS) $(CFLAGS) -o $@ $(SOURCES) $(LDLIBS)

build:
	mkdir -p $@

clean:
	rm -rf build
