#!/usr/bin/env node
'use strict';

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

async function main() {
    let inputData = '';

    // Read stdin
    await new Promise((resolve) => {
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => { inputData += chunk; });
        process.stdin.on('end', resolve);
    });

    let payload;
    try {
        payload = JSON.parse(inputData);
    } catch (e) {
        outputLine({ success: false, error: 'Invalid JSON input: ' + e.message });
        return;
    }

    const steps         = payload.steps || [];
    const globalTimeout = (payload.globalTimeout || 120) * 1000;
    const liveMode      = payload.liveMode || false;
    const screenshotDir = payload.screenshotDir || null;

    // Ensure screenshot directory exists
    if (liveMode && screenshotDir) {
        try { fs.mkdirSync(screenshotDir, { recursive: true }); } catch (_) {}
    }

    const startTime   = Date.now();
    const stepResults = [];

    let browser = null;
    let page    = null;

    try {
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({ ignoreHTTPSErrors: true });
        page = await context.newPage();

        const consoleLogs = [];

        page.on('console', (msg) => {
            const line = '[' + msg.type() + '] ' + msg.text();
            consoleLogs.push(line);
            if (liveMode) {
                outputLine({ type: 'console', level: msg.type(), text: msg.text(), t: Date.now() });
            }
        });

        // Network logging: emit as NDJSON in live mode only
        if (liveMode) {
            page.on('request', (req) => {
                const type = req.resourceType();
                if (['document', 'xhr', 'fetch'].includes(type)) {
                    outputLine({ type: 'network', kind: 'request', method: req.method(), url: req.url(), resourceType: type, t: Date.now() });
                }
            });
            page.on('response', (res) => {
                const type = res.request().resourceType();
                if (['document', 'xhr', 'fetch'].includes(type)) {
                    outputLine({ type: 'network', kind: 'response', status: res.status(), url: res.url(), resourceType: type, t: Date.now() });
                }
            });
        }

        for (const step of steps) {
            const stepStart  = Date.now();
            let   stepStatus = 'pass';
            let   errorMsg   = null;

            const timeout = step.timeout || 30000;

            try {
                await runStep(page, step, timeout);
            } catch (e) {
                stepStatus = 'fail';
                errorMsg   = e.message;
            }

            const stepLogs    = [...consoleLogs];
            consoleLogs.length = 0;

            // Capture screenshot after each step in live mode
            let screenshotFile = null;
            if (liveMode && screenshotDir) {
                try {
                    screenshotFile = 'step-' + step.sortOrder + '.jpg';
                    await page.screenshot({
                        path:     path.join(screenshotDir, screenshotFile),
                        type:     'jpeg',
                        quality:  70,
                        fullPage: false,
                    });
                } catch (_) {
                    screenshotFile = null;
                }
            }

            const stepResult = {
                sortOrder:     step.sortOrder,
                status:        stepStatus,
                durationMs:    Date.now() - stepStart,
                errorMessage:  errorMsg,
                consoleOutput: stepLogs.join('\n') || null,
            };

            // Live mode: emit one JSON line per completed step immediately
            if (liveMode) {
                outputLine({ type: 'step', screenshotFile, ...stepResult });
            }

            stepResults.push(stepResult);

            if (stepStatus === 'fail') break;

            if ((Date.now() - startTime) >= globalTimeout) {
                const tr = {
                    sortOrder:     (step.sortOrder || 0) + 1,
                    status:        'fail',
                    durationMs:    0,
                    errorMessage:  'Global timeout exceeded',
                    consoleOutput: null,
                };
                if (liveMode) outputLine({ type: 'step', screenshotFile: null, ...tr });
                stepResults.push(tr);
                break;
            }
        }

        await browser.close();

        const allPassed         = stepResults.every(s => s.status === 'pass');
        const failedStep        = stepResults.find(s => s.status === 'fail');
        const nodeVersion       = process.version;
        const playwrightPkg     = require('./node_modules/playwright/package.json');
        const playwrightVersion = playwrightPkg.version || 'unknown';

        const summary = {
            success:           allPassed,
            durationMs:        Date.now() - startTime,
            nodeVersion:       nodeVersion,
            playwrightVersion: playwrightVersion,
            failedStep:        failedStep ? failedStep.sortOrder : null,
            steps:             stepResults,
        };

        if (liveMode) {
            outputLine({ type: 'summary', ...summary });
        } else {
            outputLine(summary);
        }

    } catch (e) {
        if (browser) await browser.close().catch(() => {});
        const err = {
            success:    false,
            durationMs: Date.now() - startTime,
            error:      e.message,
            steps:      stepResults,
        };
        if (liveMode) outputLine({ type: 'summary', ...err });
        else          outputLine(err);
    }
}

async function runStep(page, step, timeout) {
    switch (step.type) {
        case 'navigate':
            await page.goto(step.value, { waitUntil: 'load', timeout });
            break;

        case 'click':
            await page.click(step.selector, { timeout });
            break;

        case 'fill':
            await page.fill(step.selector, step.value || '', { timeout });
            break;

        case 'select':
            await page.selectOption(step.selector, step.value || '', { timeout });
            break;

        case 'pressKey':
            await page.keyboard.press(step.value || 'Enter');
            break;

        case 'assertVisible':
            await page.waitForSelector(step.selector, { state: 'visible', timeout });
            break;

        case 'assertText': {
            // Wait for page to settle after possible navigation (e.g. form submit)
            try { await page.waitForLoadState('load', { timeout: Math.min(timeout, 10000) }); } catch (_) {}

            // Poll every 250ms, piercing shadow DOM so custom elements / web components work
            const deadline = Date.now() + timeout;
            let found = false;
            while (Date.now() < deadline) {
                try {
                found = await page.evaluate(([sel, txt]) => {
                    function searchInRoot(root) {
                        const elements = root.querySelectorAll(sel);
                        if (Array.from(elements).some(el =>
                            (el.innerText || el.textContent || '').includes(txt)
                        )) return true;
                        // Recurse into shadow roots
                        for (const el of root.querySelectorAll('*')) {
                            if (el.shadowRoot && searchInRoot(el.shadowRoot)) return true;
                        }
                        return false;
                    }
                    return searchInRoot(document);
                }, [step.selector, step.value || '']);
                if (found) break;
                await new Promise(r => setTimeout(r, 250));
            }
            if (!found) {
                // Gather diagnostics: text in selector + whether text exists anywhere on page
                const [actualInSel, countInSel, existsOnPage] = await page.evaluate(([sel, txt]) => {
                    // Text from matching elements
                    const els = Array.from(document.querySelectorAll(sel));
                    const texts = els
                        .map(el => (el.innerText || el.textContent || '').trim())
                        .filter(t => t);
                    const actual = texts.join(' | ').substring(0, 500);

                    // Does the text exist anywhere on the full page?
                    const bodyText = (document.body.innerText || document.body.textContent || '');
                    const anywhere = bodyText.includes(txt);

                    return [actual, els.length, anywhere];
                }, [step.selector, step.value || '']);

                let msg = `assertText: "${step.value}" not found in "${step.selector}".`;
                if (existsOnPage && countInSel > 0) {
                    msg += ` ⚠️ Text ist auf der Seite sichtbar, aber nicht in "${step.selector}" (${countInSel} Elemente gefunden). Tipp: Selector anpassen, z.B. übergeordnetes Element oder :last-child verwenden.`;
                    if (actualInSel) msg += ` Gefundener Text dort: "${actualInSel}"`;
                } else if (existsOnPage) {
                    msg += ` ⚠️ Text ist auf der Seite vorhanden, aber Selector "${step.selector}" findet kein Element.`;
                } else if (countInSel > 0) {
                    msg += ` Gefundener Text in ${countInSel} Element(en): "${actualInSel || '(leer)'}"`;
                } else {
                    msg += ` Kein Element mit Selector "${step.selector}" gefunden und Text nicht auf Seite.`;
                }
                throw new Error(msg);
            }
            break;
        }

        case 'assertUrl': {
            const url = page.url();
            if (!url.includes(step.value || '')) {
                throw new Error(`assertUrl: expected URL to contain "${step.value}" but got "${url}"`);
            }
            break;
        }

        case 'assertTitle': {
            const title = await page.title();
            if (!title.includes(step.value || '')) {
                throw new Error(`assertTitle: expected title to contain "${step.value}" but got "${title}"`);
            }
            break;
        }

        case 'waitForSelector':
            await page.waitForSelector(step.selector, { timeout });
            break;

        case 'assertNotVisible':
            await page.waitForSelector(step.selector, { state: 'hidden', timeout });
            break;

        case 'hover':
            await page.hover(step.selector, { timeout });
            break;

        case 'scroll': {
            if (step.selector) {
                // Scroll element into view
                await page.waitForSelector(step.selector, { timeout });
                await page.evaluate((sel) => {
                    const el = document.querySelector(sel);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, step.selector);
            } else {
                // Scroll page by pixels (positive = down, negative = up)
                const px = parseInt(step.value || '300', 10);
                await page.evaluate((pixels) => window.scrollBy(0, pixels), px);
            }
            // Brief settle so screenshots capture the scrolled state
            await new Promise(r => setTimeout(r, 400));
            break;
        }

        case 'waitMs': {
            const ms = Math.max(0, parseInt(step.value || '1000', 10));
            await new Promise(r => setTimeout(r, ms));
            break;
        }

        case 'assertCount': {
            const expected = parseInt(step.value || '1', 10);
            const deadline2 = Date.now() + timeout;
            let actual = -1;
            while (Date.now() < deadline2) {
                actual = await page.evaluate((sel) =>
                    document.querySelectorAll(sel).length,
                    step.selector
                );
                if (actual === expected) break;
                await new Promise(r => setTimeout(r, 250));
            }
            if (actual !== expected) {
                throw new Error(
                    `assertCount: expected ${expected} element(s) for "${step.selector}" but found ${actual}`
                );
            }
            break;
        }

        case 'check':
            await page.check(step.selector, { timeout, force: true });
            break;

        case 'uncheck':
            await page.uncheck(step.selector, { timeout, force: true });
            break;

        default:
            throw new Error(`Unknown step type: ${step.type}`);
    }
}

function outputLine(data) {
    process.stdout.write(JSON.stringify(data) + '\n');
}

main().catch((e) => {
    outputLine({ success: false, error: 'Unhandled error: ' + e.message });
    process.exit(1);
});
