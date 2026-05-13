(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(require('./redTeamTypes.js'), require('./redTeamPolicies.js'));
        return;
    }

    root.OpenEMRCopilotRedTeamReports = factory(
        root.OpenEMRCopilotRedTeamTypes,
        root.OpenEMRCopilotRedTeamPolicies
    );
}(typeof globalThis !== 'undefined' ? globalThis : this, function (Types, Policies) {
    'use strict';

    const SEVERITY_ORDER = Object.freeze([
        Types.RedTeamSeverity.CRITICAL,
        Types.RedTeamSeverity.HIGH,
        Types.RedTeamSeverity.MEDIUM,
        Types.RedTeamSeverity.LOW,
        Types.RedTeamSeverity.NONE
    ]);

    function slugify(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'redteam-report';
    }

    function buildRunSummary(runResult) {
        const result = Types.createRunResult(runResult || {});
        const verdict = result.judgeVerdict || {};
        return {
            runId: result.runId,
            scenarioId: result.plan.seedScenarioId || '',
            title: result.plan.metadata && result.plan.metadata.title ? result.plan.metadata.title : result.plan.goal || result.plan.category,
            role: result.plan.role,
            workflow: result.plan.workflow,
            category: result.plan.category,
            severity: verdict.severity || Types.RedTeamSeverity.NONE,
            violated: Boolean(verdict.violated),
            policyArea: verdict.policy_area || Policies.policyAreaForCategory(result.plan.category),
            policyLabel: verdict.policy_label || Policies.POLICY_LABELS[verdict.policy_area] || verdict.policy_area || '',
            regressionSaved: Boolean(result.regressionSaved && result.regressionSaved.saved)
        };
    }

    function summarizeSuiteResults(results) {
        const summaries = Array.isArray(results) ? results.map(buildRunSummary) : [];
        const severityCounts = {
            none: 0,
            low: 0,
            medium: 0,
            high: 0,
            critical: 0
        };

        summaries.forEach(function (item) {
            const severity = Types.normalizeSeverity(item.severity);
            severityCounts[severity] += 1;
        });

        const violatedItems = summaries.filter(function (item) {
            return item.violated;
        });

        return {
            totalRuns: summaries.length,
            violatedCount: violatedItems.length,
            safeCount: summaries.length - violatedItems.length,
            regressionSavedCount: summaries.filter(function (item) {
                return item.regressionSaved;
            }).length,
            severityCounts: severityCounts,
            topFindings: violatedItems.sort(function (left, right) {
                return SEVERITY_ORDER.indexOf(left.severity) - SEVERITY_ORDER.indexOf(right.severity);
            }).slice(0, 5),
            runs: summaries
        };
    }

    function buildSuiteMarkdown(suiteResult) {
        const suite = suiteResult && typeof suiteResult === 'object' ? suiteResult : {};
        const results = Array.isArray(suite.results) ? suite.results : [];
        const summary = suite.summary || summarizeSuiteResults(results);
        const topFindings = summary.topFindings || [];

        return [
            '# OpenEMR AI Co-Pilot OpenEMR Team Suite Report',
            '',
            '## Summary',
            'Local synthetic defensive eval harness run only. No real PHI, no external targets, and no chart writes were performed.',
            '',
            '## Run details',
            '- Suite ID: ' + String(suite.suiteId || ''),
            '- Created at: ' + String(suite.createdAt || Types.isoNow()),
            '- Total runs: ' + String(summary.totalRuns || 0),
            '- Violations found: ' + String(summary.violatedCount || 0),
            '- Safe outcomes: ' + String(summary.safeCount || 0),
            '- Regressions saved: ' + String(summary.regressionSavedCount || 0),
            '',
            '## Severity counts',
            '- Critical: ' + String(summary.severityCounts.critical || 0),
            '- High: ' + String(summary.severityCounts.high || 0),
            '- Medium: ' + String(summary.severityCounts.medium || 0),
            '- Low: ' + String(summary.severityCounts.low || 0),
            '- None: ' + String(summary.severityCounts.none || 0),
            '',
            '## Top findings',
            (topFindings.length > 0
                ? topFindings.map(function (finding) {
                    return '- [' + String(finding.severity || 'none').toUpperCase() + '] ' + finding.title
                        + ' (' + finding.role + ' / ' + finding.workflow + ')'
                        + ' - ' + (finding.policyLabel || finding.policyArea || 'Policy review');
                }).join('\n')
                : '- No policy violations were detected in this run.'),
            '',
            '## Scenario results',
            results.map(function (result) {
                const item = buildRunSummary(result);
                return '- ' + item.title
                    + ' | severity: ' + item.severity
                    + ' | violated: ' + (item.violated ? 'yes' : 'no')
                    + ' | role/workflow: ' + item.role + ' / ' + item.workflow;
            }).join('\n'),
            ''
        ].join('\n');
    }

    function buildMarkdownReportFileName(payload, options) {
        const config = options && typeof options === 'object' ? options : {};
        if (payload && Array.isArray(payload.results)) {
            return slugify(config.prefix || ('redteam-suite-' + (payload.suiteId || Types.createRunId('suite')))) + '.md';
        }

        const result = Types.createRunResult(payload || {});
        const parts = [
            config.prefix || 'redteam',
            result.plan.seedScenarioId || result.plan.category,
            result.judgeVerdict && result.judgeVerdict.severity ? result.judgeVerdict.severity : Types.RedTeamSeverity.NONE
        ].filter(Boolean);

        return slugify(parts.join('-')) + '.md';
    }

    function downloadTextFile(text, fileName, mimeType) {
        if (typeof document === 'undefined' || typeof URL === 'undefined' || typeof Blob === 'undefined') {
            return null;
        }

        const blob = new Blob([String(text || '')], { type: mimeType || 'text/plain;charset=utf-8' });
        const link = document.createElement('a');
        const href = URL.createObjectURL(blob);
        link.href = href;
        link.download = String(fileName || 'redteam-report.txt');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(href);
        return link.download;
    }

    function downloadMarkdownReport(input, options) {
        const config = options && typeof options === 'object' ? options : {};
        const markdown = typeof input === 'string'
            ? input
            : (input && Array.isArray(input.results)
                ? (input.reportMarkdown || buildSuiteMarkdown(input))
                : Types.createRunResult(input || {}).reportMarkdown);
        const fileName = config.fileName || buildMarkdownReportFileName(input, config);

        return {
            fileName: fileName,
            markdown: markdown,
            downloaded: Boolean(downloadTextFile(markdown, fileName, 'text/markdown;charset=utf-8'))
        };
    }

    return {
        buildMarkdownReportFileName: buildMarkdownReportFileName,
        buildRunSummary: buildRunSummary,
        buildSuiteMarkdown: buildSuiteMarkdown,
        downloadMarkdownReport: downloadMarkdownReport,
        downloadTextFile: downloadTextFile,
        slugify: slugify,
        summarizeSuiteResults: summarizeSuiteResults
    };
}));
