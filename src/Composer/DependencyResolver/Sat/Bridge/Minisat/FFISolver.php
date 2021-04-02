<?php

namespace Composer\DependencyResolver\Sat\Bridge\Minisat;

use FFI;
use Composer\DependencyResolver\Sat\SolverInterface;

class FFISolver implements SolverInterface
{
    /**
     * @var FFI
     */
    private $ffi;
    
    /**
     * Foreign, opaque instance of solver.
     * 
     * @var opaque
     */
    private $psolver;

    /**
     * Values for foreign boolean type.
     */
    private $lBoolTrue;
    private $lBoolFalse;
    private $lBoolUndef;
    private $varType;
    private $litType;

    public static function build() {
        echo(shell_exec('cd ' . __DIR__ . '/minisat_ffi/ && make'));
    }

    public function __construct(string $sopath = __DIR__ . '/minisat_ffi/build/libminisatffi.dylib', string $hpath = __DIR__ . '/minisat_ffi/build/minisatffi.h')
    {
        $this->ffi = FFI::cdef(file_get_contents($hpath), $sopath);

        $this->lBoolFalse = $this->ffi->minisat_get_l_False();
        $this->lBoolTrue = $this->ffi->minisat_get_l_True();
        $this->lBoolUndef = $this->ffi->minisat_get_l_Undef();

        $this->varType = $this->ffi->type('minisat_Var');
        $this->litType = $this->ffi->type('minisat_Lit');

        $this->psolver = $this->ffi->minisat_new();

    }

    public function __destruct()
    {
        $this->ffi->minisat_delete($this->psolver);
    }

    private function getLBoolValue($lBool): int 
    {
        if ($lBool === $this->lBoolFalse)
            return self::VALUE_FALSE;

        if ($lBool === $this->lBoolTrue)
            return self::VALUE_TRUE;

        return self::VALUE_UNDEFINED;
    }

    private function getLitIntValue($lit): int
    {
        $var = $this->ffi->minisat_var($lit);
        $sgn = $this->ffi->minisat_sign($lit);

        return ($sgn ? -1 : 1) * $var;
    }

    private function createVar(int $var): int
    {
        if ($var === 0) {
            throw new \InvalidArgumentException('Variable number must not be zero.');
        }

        $var = abs($var) - 1;

        // TODO: Move this loop to inlined C function

        while($var >= $this->getVariablesNumber()) {
            $this->ffi->minisat_newVar($this->psolver);
        }

        return $var;
    }

    public function setVariablePolarity(int $var, int $polarity) 
    {
        $this->ffi->minisat_setPolarity($this->psolver, $this->createVar($var), $polarity);
    }

    private $lits = [];

    private function createLit(int $literal)
    {
        if ($literal === 0) {
            throw new \InvalidArgumentException('Literal must not be zero.');
        }

        // TODO: Move this logic to C function that accepts an uint
        return $this->ffi->minisat_mkLit_args($this->createVar($literal), $literal < 0);
    }

    private function createLitArray(array $literals)
    {
        $literals = array_values($literals);
        $typ = FFI::arrayType($this->litType, [count($literals)]);
        $arr = FFI::new($typ);

        foreach ($literals as $i => $literal) {
            $arr[$i] = $this->createLit($literal);
        }

        return $arr;
    }

    public function getStatus(): int 
    {
        return $this->getLBoolValue($this->ffi->minisat_okay($this->psolver));
    }

    public function isSatisfied(): bool
    {
        return self::STATUS_SATISIFIED === $this->getStatus();
    }

    public function isUnsatisfiable(): bool
    {
        return self::STATUS_UNSATISFIABLE === $this->getStatus();
    }

    public function isStatusUndefined(): bool
    {
        return self::STATUS_UNDEFINED === $this->getStatus();
    }

    public function getVariablesNumber(): int 
    {
        return $this->ffi->minisat_num_vars($this->psolver);
    }


    public function getClausesCount(): int 
    {
        return $this->ffi->minisat_num_clauses($this->psolver);
    }

    public function getDecisionsCount(): int 
    {
        return $this->ffi->minisat_num_decisions($this->psolver);
    }

    public function getConflictLength(): int 
    {
        return $this->ffi->minisat_conflict_len($this->psolver);
    }

    public function getModelSize(): int 
    {
        return $this->ffi->minisat_model_size($this->psolver);
    }

    /**
     * Returns true if full model has been solved.
     */
    public function hasModel(): bool
    {
        return $this->getVariablesNumber() === $this->getModelSize();
    }
    
    public function hasConflict(): bool 
    {
        return $this->getConflictLength() > 0;
    }

    public function getVariableValue(int $variable): int
    {
        $variable = (int)abs($variable);

        return $this->getLBoolValue(
            $this->ffi->minisat_value_Var($this->psolver, $this->createVar($variable))
        );
    }

    public function getModelValues(): array
    {
        $model = [];

        for($var = 1; $var <= $this->getModelSize(); $var++) {
            $model[$var] = $this->getLBoolValue(
                $this->ffi->minisat_modelValue_Var($this->psolver, $this->createVar($var))
            );
        }

        return $model;
    }

    /**
     * Returns the current solution model as list of literals.
     * 
     * @return int[]
     */
    public function getModel(): array
    {
        // TODO: Maybe move to a C function that can return a static string array?
        $model = [];

        for($var = 1; $var <= $this->getModelSize(); $var++) {
            $val = $this->getLBoolValue(
                $this->ffi->minisat_modelValue_Var($this->psolver, $this->createVar($var))
            );

            if (self::VALUE_UNDEFINED === $val) {
                continue;
            }
            
            $model[] = ($val ? 1 : -1) * $var;
        }

        return $model;
    }

    /**
     * Returns the conflict literal set.
     * 
     * @return int[]
     */
    public function getConflict(): array
    {
        // TODO: Move to a C function
        $conflict = [];

        for($i = 0; $i < $this->getConflictLength(); ++$i) {
            $conflict[] = $this->getLitIntValue(
                $this->ffi->minisat_conflict_nthLit($this->psolver, (int)$i) + 1
                
            );
        }

        return $conflict;
    }

    /**
     * Returns string-indexed array of integers.
     * 
     * Note: The key names and and their vailability
     * may vary across implementations. Do not rely
     * on them if you're not sure about impl.
     * 
     * @return int[]
     */
    public function getStatistics(): array
    {
        return [
            'variables_total'       => $this->getVariablesNumber(),
            'variables_model'       => $this->getModelSize(),
            'variables_free'        => $this->ffi->minisat_num_freeVars($this->psolver),
            'variables_assigned'    => $this->ffi->minisat_num_assigns($this->psolver),
            'clauses_kept'          => $this->getClausesCount(),
            'clauses_learnt'        => $this->ffi->minisat_num_learnts($this->psolver),
            'conflict_length'       => $this->getConflictLength(),
            'decision_count'        => $this->getDecisionsCount(),
            'restarts'              => $this->ffi->minisat_num_restarts($this->psolver),
            'propagations'          => $this->ffi->minisat_num_propagations($this->psolver),
        ];
    }

    public function setDecision(int $variable, bool $decision): void
    {
        $this->ffi->minisat_setDecisionVar(
            $this->psolver,
            $this->createVar($variable),
            (int)$decision
        );
    }

    public function setDecisionLiterals(array $literals): void
    {
        // TODO: Translate into C code
        foreach($literals as $literal) {
            $this->setDecisionLiteral($literal);
        }
    }

    /**
     * Works the same as setDecision but the decision is based
     * on the literal's polarity (sign).
     */
    public function setDecisionLiteral(int $literal): void
    {
        $this->ffi->minisat_setDecisionVar(
            $this->psolver,
            $this->createVar($literal),
            (int)($literal <= 0 ? 0 : 1)
        );
    }

    /**
     * @param int[][] $clauses
     */
    public function addClauses(array $clauses): bool
    {
        foreach ($clauses as $clause) {
            if (!$this->addClause($clause)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int[] $clauses
     */
    public function addClause(array $literals): bool
    {
        // TODO: Move this to C code
        $this->ffi->minisat_addClause_begin($this->psolver);

        foreach ($literals as $literal) {
            if ($literal === 0) {
                continue;
            }

            $this->ffi->minisat_addClause_addLit($this->psolver, $this->createLit($literal));
        }

        return $this->ffi->minisat_addClause_commit($this->psolver);
    }

    public function supportSimplification(): bool
    {
        return true;
    }

    public function simplify(): bool 
    {
        return $this->ffi->minisat_simplify($this->psolver);
    }

    public function solve(array $assumptions = []): bool 
    {
        
        $res =  $this->ffi->minisat_solve(
            $this->psolver, 
            count($assumptions), 
            count($assumptions) ? $this->createLitArray($assumptions) : null
        );

        $this->ffi->minisat_set_verbosity($this->psolver, 2);

        return $res;
    }
}