<?php

declare(strict_types=1);

namespace Laraflow\Export;

use Laraflow\Data\WorkflowDefinition;
use Laraflow\Data\WorkflowMeta;

final class SvgExporter
{
    private const STATE_W = 160;
    private const STATE_H = 56;
    private const TRANSITION_W = 130;
    private const TRANSITION_H = 44;

    /** @var array<string, string> */
    private const DARK = [
        'bg' => '#0a0a14',
        'surface' => 'rgba(255,255,255,0.04)',
        'border' => 'rgba(255,255,255,0.12)',
        'text' => '#e8e8f0',
        'textMuted' => 'rgba(232,232,240,0.55)',
        'accent' => '#7dd3fc',
        'edge' => 'rgba(255,255,255,0.35)',
        'initialFill' => 'rgba(125,211,252,0.10)',
        'finalFill' => 'rgba(34,197,94,0.10)',
        'transitionFill' => 'rgba(217,70,239,0.10)',
    ];

    /** @var array<string, string> */
    private const LIGHT = [
        'bg' => '#ffffff',
        'surface' => 'rgba(0,0,0,0.03)',
        'border' => 'rgba(0,0,0,0.15)',
        'text' => '#0a0a14',
        'textMuted' => 'rgba(10,10,20,0.55)',
        'accent' => '#0284c7',
        'edge' => 'rgba(0,0,0,0.40)',
        'initialFill' => 'rgba(2,132,199,0.10)',
        'finalFill' => 'rgba(22,163,74,0.10)',
        'transitionFill' => 'rgba(168,85,247,0.10)',
    ];

    public static function export(
        WorkflowDefinition $definition,
        ?WorkflowMeta $meta = null,
        string $theme = 'dark',
    ): string {
        $nodes = self::autoLayout($definition);
        $edges = self::buildEdges($definition);

        return self::renderPositioned(
            nodes: $nodes,
            edges: $edges,
            title: $meta?->name ?? $definition->name,
            theme: $theme,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     */
    public static function renderPositioned(
        array $nodes,
        array $edges,
        ?string $title = null,
        string $theme = 'dark',
        int $padding = 24,
    ): string {
        $colors = $theme === 'light' ? self::LIGHT : self::DARK;

        if (count($nodes) === 0) {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="120" viewBox="0 0 320 120">'
                . '<rect width="100%" height="100%" fill="' . $colors['bg'] . '"/>'
                . '<text x="160" y="64" fill="' . $colors['textMuted']
                . '" text-anchor="middle" font-family="system-ui,sans-serif" font-size="12">Empty workflow</text>'
                . '</svg>';
        }

        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;

        foreach ($nodes as $n) {
            [$w, $h] = self::nodeSize($n);
            $minX = min($minX, $n['x']);
            $minY = min($minY, $n['y']);
            $maxX = max($maxX, $n['x'] + $w);
            $maxY = max($maxY, $n['y'] + $h);
        }

        $viewX = $minX - $padding;
        $viewY = $minY - $padding;
        $viewW = $maxX - $minX + $padding * 2;
        $viewH = $maxY - $minY + $padding * 2;

        $lookup = [];

        foreach ($nodes as $n) {
            $lookup[$n['id']] = $n;
        }

        $out = [];
        $out[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" width="%d" height="%d" font-family="system-ui,-apple-system,sans-serif">',
            self::num($viewX),
            self::num($viewY),
            self::num($viewW),
            self::num($viewH),
            (int) round($viewW),
            (int) round($viewH),
        );

        if ($title !== null) {
            $out[] = '<title>' . self::escapeXml($title) . '</title>';
        }

        $out[] = sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
            self::num($viewX),
            self::num($viewY),
            self::num($viewW),
            self::num($viewH),
            $colors['bg'],
        );

        $out[] = '<defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 Z" fill="' . $colors['edge'] . '"/></marker></defs>';

        foreach ($edges as $e) {
            if (! isset($lookup[$e['source']]) || ! isset($lookup[$e['target']])) {
                continue;
            }

            $s = $lookup[$e['source']];
            $t = $lookup[$e['target']];
            [$sw] = self::nodeSize($s);
            [$tw] = self::nodeSize($t);
            [$sx, $sy] = self::nodeCenter($s);
            [$tx, $ty] = self::nodeCenter($t);
            $dx = $tx - $sx;
            $sign = $dx === 0.0 || $dx === 0 ? 1 : ($dx > 0 ? 1 : -1);
            $sxEdge = $sx + $sign * ($sw / 2);
            $txEdge = $tx - $sign * ($tw / 2);
            $cpX = $sxEdge + ($txEdge - $sxEdge) * 0.5;
            $stroke = $e['color'] ?? $colors['edge'];

            $out[] = sprintf(
                '<path d="M %s %s C %s %s, %s %s, %s %s" stroke="%s" stroke-width="1.5" fill="none" marker-end="url(#arrow)"/>',
                self::num($sxEdge),
                self::num($sy),
                self::num($cpX),
                self::num($sy),
                self::num($cpX),
                self::num($ty),
                self::num($txEdge),
                self::num($ty),
                $stroke,
            );
        }

        foreach ($nodes as $n) {
            [$w, $h] = self::nodeSize($n);
            $isState = $n['type'] === 'state';
            $isInitial = $n['isInitial'] ?? false;
            $isFinal = $n['isFinal'] ?? false;

            if (isset($n['bgColor']) && $n['bgColor'] !== null) {
                $fill = $n['bgColor'];
            } elseif ($isState) {
                $fill = $isInitial
                    ? $colors['initialFill']
                    : ($isFinal ? $colors['finalFill'] : $colors['surface']);
            } else {
                $fill = $colors['transitionFill'];
            }

            $emphasized = $isState && ($isInitial || $isFinal);
            $stroke = $emphasized ? $colors['accent'] : $colors['border'];
            $rx = $isState ? 12 : 8;

            $out[] = sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" rx="%d" ry="%d" fill="%s" stroke="%s" stroke-width="%s"/>',
                self::num($n['x']),
                self::num($n['y']),
                self::num($w),
                self::num($h),
                $rx,
                $rx,
                $fill,
                $stroke,
                $emphasized ? '1.5' : '1',
            );

            $labelLines = [self::escapeXml($n['label'])];

            if ($isState && isset($n['description']) && $n['description'] !== null && $n['description'] !== '') {
                $labelLines[] = self::escapeXml($n['description']);
            }

            $fontSize = $isState ? 13 : 11;
            $lineH = $fontSize + 3;
            $totalH = count($labelLines) * $lineH;
            $startY = $n['y'] + $h / 2 - $totalH / 2 + $fontSize;

            foreach ($labelLines as $i => $line) {
                $textFill = $i === 0 ? $colors['text'] : $colors['textMuted'];
                $weight = $i === 0 ? 500 : 400;
                $fs = $i === 0 ? $fontSize : $fontSize - 1;
                $family = $isState ? 'ui-monospace,SFMono-Regular,monospace' : 'system-ui,sans-serif';

                $out[] = sprintf(
                    '<text x="%s" y="%s" fill="%s" font-size="%d" font-weight="%d" text-anchor="middle" font-family="%s">%s</text>',
                    self::num($n['x'] + $w / 2),
                    self::num($startY + $i * $lineH),
                    $textFill,
                    $fs,
                    $weight,
                    $family,
                    $line,
                );
            }
        }

        $out[] = '</svg>';

        return implode('', $out);
    }

    /**
     * Lays places into columns by BFS depth from initial marking, transitions in between.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function autoLayout(WorkflowDefinition $definition): array
    {
        $colSpacing = 220;
        $rowSpacing = 90;
        $startX = 40;
        $startY = 40;

        $placeColumn = [];

        foreach ($definition->initialMarking as $initial) {
            $placeColumn[$initial] = 0;
        }

        $changed = true;
        $safety = count($definition->places) * count($definition->transitions) + 10;

        while ($changed && $safety-- > 0) {
            $changed = false;

            foreach ($definition->transitions as $t) {
                $fromCols = [];

                foreach ($t->froms as $f) {
                    if (isset($placeColumn[$f])) {
                        $fromCols[] = $placeColumn[$f];
                    }
                }

                if (count($fromCols) === 0) {
                    continue;
                }

                $transitionCol = max($fromCols) + 1;

                foreach ($t->tos as $to) {
                    $targetCol = $transitionCol + 1;
                    $existing = $placeColumn[$to] ?? null;

                    if ($existing === null || $existing < $targetCol) {
                        $placeColumn[$to] = $targetCol;
                        $changed = true;
                    }
                }
            }
        }

        $fallbackCol = 0;

        foreach ($definition->places as $place) {
            if (! isset($placeColumn[$place->name])) {
                $placeColumn[$place->name] = $fallbackCol;
                $fallbackCol += 2;
            }
        }

        $transitionColumn = [];

        foreach ($definition->transitions as $t) {
            $maxCol = 0;

            foreach ($t->froms as $f) {
                $maxCol = max($maxCol, $placeColumn[$f] ?? 0);
            }

            $transitionColumn[$t->name] = $maxCol + 1;
        }

        $colMembers = [];

        foreach ($definition->places as $place) {
            $colMembers[$placeColumn[$place->name]][] = 'state:' . $place->name;
        }

        foreach ($definition->transitions as $t) {
            $colMembers[$transitionColumn[$t->name]][] = 'transition:' . $t->name;
        }

        ksort($colMembers);

        $placesWithOutgoing = [];

        foreach ($definition->transitions as $t) {
            foreach ($t->froms as $f) {
                $placesWithOutgoing[$f] = true;
            }
        }

        $positioned = [];

        foreach ($colMembers as $col => $members) {
            foreach ($members as $idx => $memberId) {
                [$kind, $name] = explode(':', $memberId, 2);

                if ($kind === 'state') {
                    $place = self::findPlace($definition, $name);
                    $positioned[] = [
                        'id' => $name,
                        'type' => 'state',
                        'label' => $name,
                        'description' => $place->metadata['description'] ?? null,
                        'x' => $startX + $col * $colSpacing,
                        'y' => $startY + $idx * $rowSpacing,
                        'isInitial' => in_array($name, $definition->initialMarking, true),
                        'isFinal' => ! isset($placesWithOutgoing[$name]),
                        'bgColor' => $place->metadata['bg_color'] ?? null,
                    ];
                } else {
                    $transition = self::findTransition($definition, $name);
                    $label = $name;
                    $cw = $transition->consumeWeight ?? 1;
                    $pw = $transition->produceWeight ?? 1;

                    if ($cw !== 1 || $pw !== 1) {
                        $label .= " ({$cw}:{$pw})";
                    }

                    if ($transition->guard !== null) {
                        $label .= " [{$transition->guard}]";
                    }

                    $positioned[] = [
                        'id' => $name,
                        'type' => 'transition',
                        'label' => $label,
                        'x' => $startX + $col * $colSpacing + (self::STATE_W - self::TRANSITION_W) / 2,
                        'y' => $startY + $idx * $rowSpacing,
                    ];
                }
            }
        }

        return $positioned;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function buildEdges(WorkflowDefinition $definition): array
    {
        $edges = [];

        foreach ($definition->transitions as $t) {
            foreach ($t->froms as $from) {
                $edges[] = [
                    'id' => "e-{$from}-{$t->name}",
                    'source' => $from,
                    'target' => $t->name,
                ];
            }

            foreach ($t->tos as $to) {
                $edges[] = [
                    'id' => "e-{$t->name}-{$to}",
                    'source' => $t->name,
                    'target' => $to,
                ];
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: float|int, 1: float|int}
     */
    private static function nodeSize(array $node): array
    {
        $isState = $node['type'] === 'state';
        $w = $node['width'] ?? ($isState ? self::STATE_W : self::TRANSITION_W);
        $h = $node['height'] ?? ($isState ? self::STATE_H : self::TRANSITION_H);

        return [$w, $h];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: float, 1: float}
     */
    private static function nodeCenter(array $node): array
    {
        [$w, $h] = self::nodeSize($node);

        return [$node['x'] + $w / 2, $node['y'] + $h / 2];
    }

    private static function findPlace(WorkflowDefinition $definition, string $name): \Laraflow\Data\Place
    {
        foreach ($definition->places as $place) {
            if ($place->name === $name) {
                return $place;
            }
        }

        throw new \LogicException("Place {$name} not found");
    }

    private static function findTransition(WorkflowDefinition $definition, string $name): \Laraflow\Data\Transition
    {
        foreach ($definition->transitions as $transition) {
            if ($transition->name === $name) {
                return $transition;
            }
        }

        throw new \LogicException("Transition {$name} not found");
    }

    private static function escapeXml(string $s): string
    {
        return strtr($s, [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;',
            "'" => '&apos;',
        ]);
    }

    private static function num(float|int $v): string
    {
        if (is_int($v) || $v === floor($v)) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
    }
}
