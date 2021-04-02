###################################################################################################


# Directory to store object files, libraries, executables, and dependencies:
BUILD_DIR      ?= build
CC 			   ?= $(which c++)

# Sets of compile flags for different build types
MINISAT_REL    ?= -O3 -D NDEBUG
MINISAT_DEB    ?= -O0 -D DEBUG 
MINISAT_PRF    ?= -O3 -D NDEBUG
MINISAT_FPIC   ?= -fpic

MINISAT_CXXFLAGS = -I. -D __STDC_LIMIT_MACROS -D __STDC_FORMAT_MACROS -Wno-parentheses -Wno-unused-parameter -Wextra -fPIC
MINISAT_SOFLAGS  = -dynamiclib -Wl,-headerpad_max_install_names

CXXFLAGS=-isysroot /Applications/Xcode.app/Contents/Developer/Platforms/MacOSX.platform/Developer/SDKs/MacOSX10.15.sdk

SRCS = minisat/FFI.cc
HDRS = minisat/FFI.h


.PHONY: directories  clean

all: directories libminisatffi FFI.h

directories: ${BUILD_DIR}

${BUILD_DIR}:
	mkdir -p ${BUILD_DIR}

libminisatffi:
	c++ -I.  -o ${BUILD_DIR}/$@.dylib -install_name @rpath/$@.dylib ${CXXFLAGS} ${MINISAT_CXXFLAGS} ${MINISAT_SOFLAGS} minisat/FFI.cc

FFI.h:
	c++ -E minisat/$@ -o ${BUILD_DIR}/minisatffi.h

clean:
	rm -rf ${BUILD_DIR}
