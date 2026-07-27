<?php
/**
 * Zentraler Einstiegspunkt für Preisprüfung und Verkaufspreiskalkulation.
 *
 * Die Implementierungen bleiben in ihren bestehenden Fachmodulen; dieser
 * Bootstrap verhindert abweichende Include-Reihenfolgen.
 */
require_once __DIR__ . '/price_guard.php';
require_once __DIR__ . '/pricing_rules.php';
