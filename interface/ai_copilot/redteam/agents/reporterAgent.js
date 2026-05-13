(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(
            require('../redTeamTypes.js'),
            require('../redTeamPolicies.js')
        );
        return;
    }

    root.OpenEMRCopilotReporterAgent = factory(
        root.OpenEMRCopilotRedTeamTypes,
        root.OpenEMRCopilotRedTeamPolicies
    );
}(typeof globalThis !== 'undefined' ? globalThis : this, function (Types, Policies) {
    'use strict';

    function buildMarkdown(runResult) {
        const result = Types.createRunResult(runResult || {});
        const verdict = result.judgeVerdict || {};
        const responseText = result.copilotResponse && (result.copilotResponse.plainText || result.copilotResponse.responseText || result.copilotResponse.content)
            ? String(result.copilotResponse.plainText || result.copilotResponse.responseText || result.copilotResponse.content)
            : 'No response captured.';
        const severity = verdict.severity || Types.RedTeamSeverity.NONE;
        const policyLabel = verdict.policy_label || Policies.POLICY_LABELS[verdict.policy_area] || verdict.policy_area || 'Policy review';
        const recommendedFix = verdict.recommended_fix || Policies.recommendedFixForPolicyArea(verdict.policy_area);
        const regressionLabel = result.regressionSaved && result.regressionSaved.saved
            ? 'Yes'
            : (result.regressionSaved && result.regressionSaved.duplicate ? 'Duplicate already existed' : 'No');

        return [
            '# ' + '[' + severity.toUpperCase() + '] ' + policyLabel + ' OpenEMR Team finding',
            '',
            '## Summary',
            result.plan.goal || 'Local synthetic OpenEMR Team evaluation run.',
            '',
            '## Severity',
            severity,
            '',
            '## Affected role/workflow',
            '- Role: ' + result.plan.role,
            '- Workflow: ' + result.plan.workflow,
            '- Synthetic patient: ' + result.plan.patientName + ' (' + result.plan.patientId + ')',
            '',
            '## Attack prompt',
            '```text',
            result.selectedPrompt || result.generatedPrompt || '',
            '```',
            '',
            '## Observed response',
            '```text',
            responseText,
            '```',
            '',
            '## Violated policy',
            policyLabel,
            '',
            '## Expected behavior',
            verdict.expected_behavior || result.plan.expectedSafeBehavior || '',
            '',
            '## Observed behavior',
            verdict.observed_behavior || '',
            '',
            '## Evidence',
            (Array.isArray(verdict.evidence_quotes) && verdict.evidence_quotes.length > 0
                ? verdict.evidence_quotes.map(function (quote) {
                    return '- ' + quote;
                }).join('\n')
                : '- No evidence quotes captured.'),
            '',
            '## Reproduction steps',
            '1. Open the local OpenEMR AI Co-Pilot demo.',
            '2. Set the synthetic role to `' + result.plan.role + '`.',
            '3. Use the synthetic patient `' + result.plan.patientName + '`.',
            '4. Run the OpenEMR Team scenario for `' + result.plan.workflow + '`.',
            '5. Submit the prompt shown above.',
            '6. Compare the observed response to the expected safe behavior.',
            '',
            '## Recommended fix',
            recommendedFix,
            '',
            '## Regression test added',
            regressionLabel,
            ''
        ].join('\n');
    }

    function createReporterAgent() {
        return {
            buildMarkdown: buildMarkdown
        };
    }

    return {
        buildMarkdown: buildMarkdown,
        createReporterAgent: createReporterAgent
    };
}));
