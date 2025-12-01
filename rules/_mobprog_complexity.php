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

if (!function_exists('analyzeMobProgComplexity'))
{
    function analyzeMobProgComplexity($lines)
    {
        $cyclomatic = 1;
        $cognitive = 0;
        $nestingLevel = 0;

        foreach ($lines as $line) 
        {
            $cleaned = preg_replace('/#.*$/', '', $line);
            $cleaned = trim($cleaned);
            if (empty($cleaned))
                continue;
            $decisionPoints = 0;
            if (preg_match_all('/\bif\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\belse\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bfor\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bmpwhile\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\bcase\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match_all('/\breturn\b/i', $cleaned, $matches)) 
            {
                $count = count($matches[0]);
                $cyclomatic += $count;
                $decisionPoints += $count;
            }
            if (preg_match('/\b(if|for|mpwhile|switch)\b/i', $cleaned)) 
                $nestingLevel++;
            if (preg_match('/\b(endif|next)\b/i', $cleaned))
                $nestingLevel = max(0, $nestingLevel - 1);
            $cognitive += $decisionPoints * (1 + $nestingLevel);
        }

        return [
            'cyclomatic' => $cyclomatic,
            'cognitive' => $cognitive
        ];
    }
}
