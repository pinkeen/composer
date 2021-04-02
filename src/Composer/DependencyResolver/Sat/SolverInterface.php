<?php

namespace Composer\DependencyResolver\Sat;

interface SolverInterface
{
    const VALUE_FALSE           = 0;
    const VALUE_TRUE            = 1;
    const VALUE_UNDEFINED       = 2;

    const VALUE_NAMES = [
        self::VALUE_FALSE       => 'False',
        self::VALUE_TRUE        => 'True',
        self::VALUE_UNDEFINED   => 'Undef',
    ];

    const STATUS_UNSATISFIABLE  = self::VALUE_FALSE;
    const STATUS_SATISIFIED     = self::VALUE_TRUE;
    const STATUS_UNDEFINED      = self::VALUE_UNDEFINED;

    
    public function getStatus(): int;

    public function isSatisfied(): bool;

    public function isUnsatisfiable(): bool;

    /**
     * This status occurs when neither satisfiability or otherwise
     * has not been proven because solution was interrupted for some
     * reason.
     */
    public function isStatusUndefined(): bool;

    /**
     * Returns the highest variable number.
     * 
     * Note: Even if you do not explicitly add variables in-between
     *       the holes are filled automatically.
     */
    public function getVariablesNumber(): int;

    /**
     * Returns the number of active clauses. 
     * 
     * Note: Clauses which value is known at once are not counted
     *       towards this number.
     */
    public function getClausesCount(): int;

    public function getDecisionsCount(): int;

    public function getConflictLength(): int;

    public function hasConflict(): bool;

    /**
     * Returns the conflict literal set.
     * 
     * @return int[]
     */
    public function getConflict(): array;

    /**
     * Returns true if full model has been solved.
     */
    public function hasModel(): bool;

    /**
     * Returns the current solution model as list of literals.
     * 
     * Note: Incomplete model may be returned in some edge cases.
     *       Use hasModel to check if we have a full solution.
     *
     * @return int[]
     */
    public function getModel(): array;

    /**
     * Returns full model as an array indexed with var number
     * containg true/false/undef value.
     */
    public function getModelValues(): array;
    
    /**
     * Returns the currently-known value of the specified variable.
     */
    public function getVariableValue(int $variable): int;

    public function setVariablePolarity(int $var, int $polarity);
    /**
     * Returns string-indexed array of integers.
     *
     * Note: The key names and and their vailability
     *       may vary across implementations. Do not rely
     *       on them if you're not sure about impl.
     *
     * @return int[]
     */
    public function getStatistics(): array;

    /**
     * Sets an axiomatic decision.
     */
    public function setDecision(int $variable, bool $decision): void;

    /**
     * Works the same as setDecision but the decision is based
     * on the literal's polarity (sign).
     */
    public function setDecisionLiteral(int $literal): void;

    /**
     * Sets a batch of decisions.
     * 
     * @param int[] $literals
     */
    public function setDecisionLiterals(array $literals): void;

    /**
     * @param int[][] $clauses
     */
    public function addClauses(array $clauses): bool;

    /**
     * @param int[] $clauses
     */
    public function addClause(array $literals): bool;

    /**
     * Whether the call to simplify() will have any effect.
     */
    public function supportSimplification(): bool;

    /**
     * Perform simplification if supported. You usually want to
     * do this before running solve().
     */
    public function simplify(): bool;

    /**
     * Run the actual solver. Call this method after all input
     * data (clauses, decisions, ...) has been populated.
     */
    public function solve(array $assumptions = []): bool;
}