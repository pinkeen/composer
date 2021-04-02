cmake_minimum_required(VERSION 3.16 FATAL_ERROR)

project(minisat)

#--------------------------------------------------------------------------------------------------
# Configurable options:

# option(STATIC_BINARIES "Link binaries statically." ON)

#--------------------------------------------------------------------------------------------------
# Dependencies:

# find_package(ZLIB)
# include_directories(${ZLIB_INCLUDE_DIR})
include_directories(${minisat_SOURCE_DIR})

#--------------------------------------------------------------------------------------------------
# Compile flags:

add_definitions(-D__STDC_FORMAT_MACROS -D__STDC_LIMIT_MACROS)

#--------------------------------------------------------------------------------------------------
# Build Targets:

set(MINISAT_LIB_SOURCES minisat/FFI.cc)

add_library(minisat-lib-ffi SHARED ${MINISAT_LIB_SOURCES})

target_link_libraries(minisat-lib-ffi)
set_target_properties(minisat-lib-ffi PROPERTIES OUTPUT_NAME "minisat_ffi")

#--------------------------------------------------------------------------------------------------
# Installation targets:

install(TARGETS minisat-lib-ffi minisat-lib-ffi
        RUNTIME DESTINATION bin
        LIBRARY DESTINATION lib)

install(DIRECTORY minisat/ffi
        DESTINATION include/minisat
        FILES_MATCHING PATTERN "*.h")
