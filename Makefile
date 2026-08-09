CXX ?= c++
CXXFLAGS ?= -O2 -pipe
QT6_GUI_FLAGS := $(shell pkg-config --cflags --libs Qt6Gui)

.PHONY: desktop-awareness

desktop-awareness: build/navi-brain-idle

build/navi-brain-idle: src/Native/desktop-idle.cpp
	mkdir -p build
	$(CXX) $(CXXFLAGS) -std=c++17 -I/usr/include/KF6 -I/usr/include/KF6/KIdleTime $< -o $@ $(QT6_GUI_FLAGS) -lKF6IdleTime
