<?php

namespace Composer\DependencyResolver\Sat\Adapter\Composer;

use Composer\DependencyResolver\Sat\SolverInterface;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\RuleSet;
use Composer\DependencyResolver\Rule;
use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Problem;
use Composer\DependencyResolver\Decisions;
use Composer\DependencyResolver\MultiConflictRule;
use Composer\IO\IOInterface;
use ReflectionClass;

class DependencyResolverAdapter
{
    /**
     * @var SolverInterface
     */
    protected $solver;

    /**
     * @var Pool|null
     */
    protected $pool;

    /**
     * @var callable|null
     */
    protected $log;

    /**
     * @var string
     */
    protected $logLinePrefix = '';

    /**
     * @var int
     */
    static $solveRunNr = 0;

    /**
     * @param $log IOInterface|callable|null
     */
    public function __construct(SolverInterface $solver, Pool $pool = null, $log = null)
    {
        // if (self::$solveRunNr == 0) $solver->build();

        if ($log instanceof IOInterface) {
            /** @var $io IOInterface */
            $io = $log;

            $log = function($message) use ($io) {
                $io->writeError($message); //, true, IOInterface::DEBUG);
            };
        }

        if (!is_callable($log)) {
            throw new \InvalidArgumentException('Expected log to be a callable, null or isntance of IOInterface.');
        }

        $this->solver = $solver;
        $this->pool = $pool;
        $this->log = $log;

        $this->logLinePrefix = sprintf('%s | ', self::shcn(get_class($this->solver)));
    }

    protected function log($message, ...$args)
    {
        if (!$this->log) {
            return;
        }

        $message = $this->logLinePrefix . str_replace("\n", "\n{$this->logLinePrefix}", $message);

        ($this->log)(sprintf($message, ...$args));
    }

    protected function statsToString(array $stats, string $title = 'SAT Solver Statistics')
    {
        ksort($stats);
        $labels = array_map([$this, 'humanizeSnakeCase'], array_keys($stats));
        $values = array_map('strval', array_values($stats));
        $labelsLen = max(array_map('strlen', $labels));
        $valuesLen = max(array_map('strlen', $values));
        $totalLen = max($labelsLen + $valuesLen + 5, strlen($title) + 4);

        $str = "\n <info>" . str_repeat(' ', floor(($totalLen - strlen($title)) / 2)) . $title . "</info>\n";
        $str .= str_repeat('=', $totalLen + 2) . "\n";

        foreach (array_combine($labels, $values) as $label => $value) {
            $str .= " <comment>" . $label . '</comment> ' . str_repeat('.', $totalLen - strlen($label) - strlen($value) - 2) . ' ' .$value;
            $str .= "\n";
        }

        return $str;
    }

    protected function literalToString(int $literal)
    {
        $symbol = $literal <= 0 ? "---" : "+++";

        $str = $symbol . ' ' . abs($literal);

        if (!$this->pool) {
            return $str;
        }

        if (!$pkg = $this->pool->literalToPackage($literal)) {
            return $str;
        }

        return $symbol . ' ' . strval($pkg) . ':' . abs($literal);
    }

    static protected function unqcn(string $fqcn)
    {
        return substr($fqcn, (strrpos($fqcn, '\\') ?: -1) + 1);
    }

    static protected function shcn(string $fqcn, $maxParts = 2)
    {
        return implode('\\', array_slice(explode('\\', ltrim($fqcn, '\\')), -$maxParts));
    }

    static protected function humanizeSnakeCase(string $snake_cased): string 
    {
        return ucwords(str_replace('_', ' ', $snake_cased));
    }

    protected function ruleToString(Rule $rule) 
    {
        return sprintf('%s <%s, %s> ( %s )', 
            $this->unqcn(get_class($rule)), 
            $this->getRuleTypeName($rule->getType()), 
            $this->getRuleReasonName($rule->getReason()),
            $this->literalsToString($rule->getLiterals())
        );
    }

    protected function decisionToString(array $decision)
    {
        $literal = $decision[Decisions::DECISION_LITERAL];
        $str = sprintf('Decision [%03d] ', abs($literal));

        $why = $decision[Decisions::DECISION_REASON];

        if (!$why instanceof Rule) {
            return $str;
        }

        return $str . ' ' . $this->ruleToString($why);
    }

    /**
     * @param int[]
     */
    protected function literalsToString(array $literals)
    {
        return implode(' | ', array_map([$this, 'literalToString'], $literals));
    }

    /**
     * @param int[] $literals
     */
    protected function getLiteralsHash(array $literals)
    {
        sort($literals); return crc32(implode(',', $literals));
    }

    private function createFileWriter(string $filename, bool $append = false): object
    {
        return new class($filename, $append) {   
            private $file;
            public function __construct($filename, $append) {
                $this->file = fopen($filename, $append ? "a" : "w");
            }
            public function write(callable $writer) {
                $writer(function(string $line) {
                    fwrite($this->file, $line . "\n");
                });
                fflush($this->file);
                return $this;
            }
            public function __destruct() {
                fclose($this->file);
            }
        };
    }

    private function logClauses(array $clauses, $writeLn = null)
    {
        $writeLn = $writeLn ?: [$this, 'log'];

        foreach ($clauses as $clause) {
            $writeLn('Clause ( ' . $this->literalsToString($clause) . ' )');
        }
    }

    private function logDecisions(Decisions $decisions, $writeLn = null) 
    {
        $writeLn = $writeLn ?: [$this, 'log'];

        foreach ($decisions as $decision) {
            $writeLn($this->decisionToString($decision));
        }
    }

    private function logRules(RuleSet $rules, $writeLn = null) 
    {
        $writeLn = $writeLn ?: [$this, 'log'];

        foreach ($rules as $rule) {
            $writeLn($this->ruleToString($rule));
        }
    }

    private function getRuleTypeName(int $type): string
    {
        static $names = null;

        if (null === $names) {
            $names = array_map(
                fn($name) => str_replace('TYPE_', '', $name),
                array_flip((new ReflectionClass(RuleSet::class))->getConstants())
            );
        }

        return $names[$type];
    }

    private function getRuleReasonName(int $reason): string
    {
        static $names = null;

        if (null === $names) {
            $names = array_map(
                fn($name) => str_replace('RULE_', '', $name),
                array_flip((new ReflectionClass(Rule::class))->getConstants())
            );
        }

        return $names[$reason];
    }

    private function logPackageModel($onlyTrue = false, $writeLn = null)
    {
        static $sym = [
            SolverInterface::VALUE_UNDEFINED => '???',
            SolverInterface::VALUE_TRUE => '+++',
            SolverInterface::VALUE_FALSE => '---',
        ];

        $writeLn = $writeLn ?: [$this, 'log'];

        foreach ($this->solver->getModelValues() as $var => $val) {  
            if ($onlyTrue && $val <= 0) {
                continue;
            }

            $pkg = $this->pool->literalToPackage($var);
            $writeLn("Var {$sym[$val]} [$var] $pkg");
        }
    }

    private function logPackageVariables($writeLn = null)
    {
        static $sym = [
            SolverInterface::VALUE_UNDEFINED => '???',
            SolverInterface::VALUE_TRUE => '+++',
            SolverInterface::VALUE_FALSE => '---',
        ];

        $writeLn = $writeLn ?: [$this, 'log'];

        for ($var = 1; $var <= $this->solver->getVariablesNumber(); $var++) {
            $val = $this->solver->getVariableValue($var);
            $pkg = $this->pool->literalToPackage($var);
            $writeLn("Var {$sym[$val]} [$var] $pkg");
        }
    }

    private function writeDebugFiles($model, $decisions, $problems, $rules, $clauses = [])
    {
        static $nr = 0;
        $nr++;

        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'model.txt')
            ->write(fn ($writer) => $this->logPackageModel(true, $writer));

        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'model_full.txt')
            ->write(fn ($writer) => $this->logPackageModel(false, $writer));

        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'vars.txt')
            ->write(fn ($writer) => $this->logPackageVariables($writer));

        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'decisions.txt')
            ->write(fn ($writer) => $this->logDecisions($decisions, $writer));

        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'clauses.txt')
            ->write(fn ($writer) => $this->logClauses($clauses, $writer));
            
        $this->createFileWriter(self::$solveRunNr . "-{$nr}-" . 'rules.txt')
            ->write(fn ($writer) => $this->logRules($rules, $writer));

    }

    /**
     * @return int[]
     */
    protected function decisionsToAssumptions(Decisions $decisions): array
    {
        $assumptions = [];

        foreach ($decisions as $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];
            $assumptions[] = $literal;
        }

        return $assumptions;
    }

    
    protected function ruleToClauses(Rule $rule): \Generator
    {
        if (!$rule->isEnabled()) {
            yield [];
        }
        
        $literals = $rule->getLiterals();
        $literals = array_values($literals);

        if (!$rule instanceof MultiConflictRule) {
            yield $literals;

            return;
        }

        // All of the literals are negative so do a reverse-sort
        // so combinations are added in the absolute variable value order
        rsort($literals);
        
        $n = count($literals);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $n - 1; $j >= $i + 1; $j--) {
                yield [ $literals[$i], $literals[$j] ];
            }
        }
    }

    protected function transformRules(RuleSet $rules): \Generator
    {
        $clauses = [];

        foreach ($rules->getRules() as $type => $typeRules) {
            foreach ($typeRules as $rule) {
                foreach ($this->ruleToClauses($rule) as $clause) {
                    if (1 == count($clause)) {
                        yield $clause;
                        continue;
                    }

                    $clauses[] = $clause;
                }
            }
        }

        foreach ($clauses as $clause) {
            yield $clause;
        }

    }

    /**
     * @param int[] $model List of computed literals
     */
    protected function applyModel(array $model, Decisions $decisions, array &$problems, RuleSet $rules = null)
    {
        foreach ($model as $literal) {
            $prevRule = $decisions->decisionRule($literal);

            if ($prevRule) {
                $newRule = new GenericRule([$literal], $prevRule->getReason(), $prevRule->getReasonData());
                $newRule->setType($prevRule->getType());
            } else {
                $newRule = new GenericRule([$literal], Rule::RULE_FIXED, $this->pool->literalToPackage($literal));
                $newRule->setType(RuleSet::TYPE_PACKAGE);
            }

            if ($rules) {
                $rules->add($newRule, $newRule->getType());
            }

            if ($decisions->decided($literal)) {
                if ($decisions->conflict($literal)) {
                    $conflictingRule = $decisions->decisionRule($literal);

                    if ($conflictingRule && $conflictingRule->getType() !== RuleSet::TYPE_REQUEST) {
                        $newRule->disable();

                        $problem = new Problem();
                        $problem->addRule($newRule);
                        $problem->addRule($conflictingRule);

                        $problems[] = $problem;                        
                    } // else - Do we need to handle conflicts for other cases?
                } // else - Nothing to do if satisfies the rule...
            } else {
                $decisions->decide($literal, $decisions->decisionLevel($literal) + 1, $newRule);
            }
        }
    }

    protected function runSolve(array $assumptions = []): array
    {
        $this->solver->solve($assumptions);
        $this->log($this->statsToString($this->solver->getStatistics()));

        if (!$this->solver->isSatisfied() || !$this->solver->hasModel()) {
            foreach ($this->solver->getConflict() as $literal) {
                $this->log('Conflict ' . $this->literalToString($literal));
            }

            throw new \Exception('Sat solver could not produce a solvable model: ');
        }
        
        return $this->solver->getModel();
    }

    protected function stats(string $label, callable $work)
    {
        $before = microtime(true);
        $beforeMemory = memory_get_usage(true);

        $this->log(sprintf('Start timed %s', $label));

        $result = $work();

        $afterMemory = memory_get_peak_usage(true);

        $this->log(sprintf('Finish timed %s <info>%.2f</info> seconds with <info>%.2f MB</info> (%.2f MB -> %.2f MB) memory usage.', 
            $label,
            microtime(true) - $before,
            ($afterMemory - $beforeMemory) / 1E6,
            $beforeMemory / 1E6,
            $afterMemory / 1E6
        ));

        return $result;
    }

    public function solve(RuleSet $rules, Decisions $decisions, array &$problems, bool $logStats = true): bool
    {
        self::$solveRunNr++;

        // $clauses = $this->stats('computeClauses', function() use ($rules) {
        //     return iterator_to_array($this->transformRules($rules));
        // });

        // $this->stats('addClauses', function() use ($clauses) {
        //     foreach ($clauses as $clause) {
        //         $this->solver->addClause($clause);
        //     }
        // });

        $this->stats('transformAndAddClauses', function() use ($rules) {
            foreach ($this->transformRules($rules) as $clause) {
                $this->solver->addClause($clause);
            }
        });

        $model = $this->stats('runSolve', function() {
            return $this->runSolve();
        });
        // $this->writeDebugFiles($model, $decisions, $problems, $rules);

        $this->stats('applyModel', function() use ($model, $decisions, $problems, $rules) {
            $this->applyModel($model, $decisions, $problems, $rules);
        });

        return true;
    }

}
