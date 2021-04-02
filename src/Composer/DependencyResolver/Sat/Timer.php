<?php

namespace Composer\DependencyResolver\Sat;

class Timer
{
    private $section = null;
    private $sections = [];
    private $gcCollect = false;
    private $tree = [];

    public function __construct(bool $gcCollect = true)
    {
        $this->gcCollect = $gcCollect;
    }

    private function snapData()
    {
        return [microtime(true), memory_get_usage(true), memory_get_peak_usage(true)];
    }

    private function snap()
    {
        $sections[$this->section][] = $this->snapData();
    }

    public function begin($section = null) 
    {
        if ($this->section !== $section) {
            $this->sectionStack[] = $section;
        }
        
        $this->lap();
    }

    public function lap()
    {
        if (empty($this->sectionStack)) {
            throw new \Exception("No started section to record lap for");
        }

        if ($this->gcCollect) {
            if (!gc_enabled()) {
                gc_enable();
                gc_collect_cycles();
                gc_disable();
            } else {
                gc_collect_cycles();
            }
        }

        $this->snap();
    }

    public function end($section = null) 
    {
        if (empty($this->sectionStack)) {
            throw new \Exception("No started sections to end");
        }

        if ($this->section !== $section) {
            throw new \Exception("Cannot end section $section as the current section is {$this->section}");
        }

        $this->snap();
        $this->section = array_pop($this->sectionStack);
    }
}