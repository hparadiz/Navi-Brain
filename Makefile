CXX ?= c++
CXXFLAGS ?= -O2 -pipe
CARGO ?= cargo
QT6_GUI_FLAGS := $(shell pkg-config --cflags --libs Qt6Gui)

.PHONY: continuity-supervisor desktop-awareness local-model local-model-check install-local-model

continuity-supervisor:
	$(CARGO) build --release --manifest-path supervisor/Cargo.toml

# Run the baseline local model in the foreground with the production ceilings.
local-model:
	./bin/navi-brain-local-model

# Confirm the endpoint answers and report what the executive sees.
local-model-check:
	./bin/navi-brain local:status

install-local-model:
	install -m 0755 packaging/openrc/navi-brain-local-model /etc/init.d/navi-brain-local-model
	@echo "Installed. Enable with: rc-update add navi-brain-local-model default"

desktop-awareness: build/navi-brain-idle

build/navi-brain-idle: src/Native/desktop-idle.cpp
	mkdir -p build
	$(CXX) $(CXXFLAGS) -std=c++17 -I/usr/include/KF6 -I/usr/include/KF6/KIdleTime $< -o $@ $(QT6_GUI_FLAGS) -lKF6IdleTime
