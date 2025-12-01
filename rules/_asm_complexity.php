<?php
/*
 Copyright 2025-2025 Bo Zimmerman

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 */

if (!function_exists('analyzeAssemblyComplexity'))
{
    /**
     * Analyzes cyclomatic and cognitive complexity for Assembly
     *
     * @param array $lines Array of code lines to analyze
     * @return array ['cyclomatic' => int, 'cognitive' => int]
     */
    function analyzeAssemblyComplexity($lines)
    {
        $cyclomatic = 1; // Base complexity starts at 1
        $cognitive = 0;
        $nestingLevel = 0;
        $labelStack = [];

        foreach ($lines as $line) {
            // Remove comments
            $cleaned = preg_replace('/;.*$/', '', $line);
            $cleaned = trim($cleaned);

            if (empty($cleaned)) {
                continue;
            }

            // Count decision points for cyclomatic complexity
            $decisionPoints = 0;

            // Conditional jumps (x86/x64)
            // JE/JZ, JNE/JNZ, JG, JGE, JL, JLE, JA, JAE, JB, JBE, JS, JNS, JO, JNO, JP, JNP, JC, JNC
            if (preg_match_all('/\b(J[EZNOLGABSCPN][EABOL]?|J[PC]|JN[ZSOCPE])\b/i', $cleaned, $matches)) {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }

            // Conditional branches (ARM)
            // BEQ, BNE, BLT, BGT, BLE, BGE, BCS, BCC, BMI, BPL, BVS, BVC, BHI, BLS
            if (preg_match_all('/\bB(EQ|NE|LT|GT|LE|GE|CS|CC|MI|PL|VS|VC|HI|LS)\b/i', $cleaned, $matches)) {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }

            // Loop instructions
            // LOOP, LOOPE/LOOPZ, LOOPNE/LOOPNZ
            if (preg_match_all('/\bLOOP(NE|NZ|E|Z)?\b/i', $cleaned, $matches)) {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }

            // CALL instructions (function calls add complexity)
            if (preg_match_all('/\bCALL\b/i', $cleaned, $matches)) {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }

            // Conditional moves (x86)
            if (preg_match_all('/\bCMOV[EZNOLGAB][EABOL]?\b/i', $cleaned, $matches)) {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }

            // Track nesting by labels and jumps
            // Labels typically indicate potential jump targets
            if (preg_match('/^(\w+):/', $cleaned)) {
                // New label - could be loop start or branch target
                $nestingLevel = max(0, $nestingLevel);
            }

            // RET reduces nesting (return from call)
            if (preg_match('/\bRET\b/i', $cleaned)) {
                $nestingLevel = max(0, $nestingLevel - 1);
            }

            // Update cognitive complexity with nesting weight
            $cognitive += $decisionPoints * (1 + $nestingLevel);
        }

        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
