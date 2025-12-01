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

return [
    'total_lines' => [
        'column' => 'total_lines',
        'label' => 'Total Lines',
        'shortLabel' => 'Total',
        'description' => 'All lines in the file including code, comments, and blank lines',
        'chartColor' => '#007bff',
        'yAxisLabel' => 'Total Lines',
        'chartTitle' => 'Total Lines Over Time'
    ],
    'code_lines' => [
        'column' => 'code_lines',
        'label' => 'Code Lines',
        'shortLabel' => 'Code',
        'description' => 'Lines containing actual source code (excluding comments and blank lines)',
        'chartColor' => '#28a745',
        'yAxisLabel' => 'Lines of Code',
        'chartTitle' => 'Lines of Code Over Time'
    ],
    'code_statements' => [
        'column' => 'code_statements',
        'label' => 'Code Statements',
        'shortLabel' => 'Statements',
        'description' => 'Number of executable statements or declarations in the code',
        'chartColor' => '#17a2b8',
        'yAxisLabel' => 'Number of Statements',
        'chartTitle' => 'Code Statements Over Time'
    ],
    'weighted_code_statements' => [
        'column' => 'weighted_code_statements',
        'label' => 'Weighted Code Statements',
        'shortLabel' => 'Weighted Stmts',
        'description' => 'Statements weighted by expressiveness',
        'chartColor' => '#6f42c1',
        'yAxisLabel' => 'Weighted Statements',
        'chartTitle' => 'Weighted Statements Over Time'
    ],
    'weighted_code_lines' => [
        'column' => 'weighted_code_lines',
        'label' => 'Weighted Code Lines',
        'shortLabel' => 'Weighted Lines',
        'description' => 'Code lines weighted by expressiveness',
        'chartColor' => '#fd7e14',
        'yAxisLabel' => 'Weighted Lines',
        'chartTitle' => 'Weighted Lines Over Time'
    ],
    'ncloc' => [
        'column' => 'ncloc',
        'label' => 'Non-Comment Lines of Code',
        'shortLabel' => 'NCLOC',
        'description' => 'Physical lines that are not comments or blank (industry standard metric)',
        'chartColor' => '#20c997',
        'yAxisLabel' => 'NCLOC',
        'chartTitle' => 'NCLOC Lines Over Time'
    ],
    'cyclomatic_complexity' => [
        'column' => 'cyclomatic_complexity',
        'label' => 'Cyclomatic Complexity',
        'shortLabel' => 'Cyclomatic',
        'description' => 'McCabe cyclomatic complexity - measures the number of linearly independent paths through code',
        'chartColor' => '#dc3545',
        'yAxisLabel' => 'Cyclomatic Complexity',
        'chartTitle' => 'Cyclomatic Complexity Over Time'
    ],
    'cognitive_complexity' => [
        'column' => 'cognitive_complexity',
        'label' => 'Cognitive Complexity',
        'shortLabel' => 'Cognitive',
        'description' => 'SonarSource cognitive complexity - measures how difficult code is to understand, with higher weights for nested structures',
        'chartColor' => '#e83e8c',
        'yAxisLabel' => 'Cognitive Complexity',
        'chartTitle' => 'Cognitive Complexity Over Time'
    ]
];