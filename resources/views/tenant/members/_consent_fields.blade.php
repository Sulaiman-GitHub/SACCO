@php
    $currentScoring = old('scoring_consent', isset($member) && $member->scoring_consent !== null ? (int) $member->scoring_consent : null);
    $kinInformed = old('next_of_kin_informed', isset($member) ? (int) $member->next_of_kin_informed : 0);
@endphp

<div class="text-muted mb-3" style="font-size: 12px; line-height: 1.5;">
    Read the member-facing consent notice (version {{ \App\Models\Member::CONSENT_NOTICE_VERSION }}) to the member, in a language they understand, before asking for their answers. Both scoring answers are acceptable. A member who declines scoring can still apply for a loan, which is decided by an officer without a score.
</div>

<div class="form-check mb-3">
    <input class="form-check-input @error('consent_data_processing') is-invalid @enderror" type="checkbox" value="1" id="consent_data_processing" name="consent_data_processing" {{ old('consent_data_processing') ? 'checked' : '' }}>
    <label class="form-check-label" for="consent_data_processing" style="font-size: 13px;">
        The member agrees that the SACCO may collect and use their personal information to manage their membership, savings and loans. <span class="text-danger">*</span>
    </label>
    @error('consent_data_processing')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <div class="fw-semibold mb-1" style="font-size: 13px;">
        Credit scoring: may the member's savings and repayment history with this SACCO be used to calculate a credit risk score when they apply for a loan? <span class="text-danger">*</span>
    </div>
    <div class="text-muted mb-1" style="font-size: 12px;">Scoring uses the member's data, so this can only be answered after they agree to the SACCO using their information above.</div>
    <div class="form-check">
        <input class="form-check-input @error('scoring_consent') is-invalid @enderror" type="radio" name="scoring_consent" id="scoring_consent_yes" value="1" {{ (string) $currentScoring === '1' ? 'checked' : '' }}>
        <label class="form-check-label" for="scoring_consent_yes" style="font-size: 13px;">Yes, the member agrees</label>
    </div>
    <div class="form-check">
        <input class="form-check-input @error('scoring_consent') is-invalid @enderror" type="radio" name="scoring_consent" id="scoring_consent_no" value="0" {{ (string) $currentScoring === '0' ? 'checked' : '' }}>
        <label class="form-check-label" for="scoring_consent_no" style="font-size: 13px;">No, the member declines (loans are decided manually, without a score)</label>
    </div>
    @error('scoring_consent')
        <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
    @enderror
</div>

<script>
    (function () {
        var processing = document.getElementById('consent_data_processing');
        var scoringInputs = document.querySelectorAll('input[name="scoring_consent"]');

        function syncScoringAvailability() {
            scoringInputs.forEach(function (input) {
                input.disabled = !processing.checked;
                if (!processing.checked) {
                    input.checked = false;
                }
            });
        }

        processing.addEventListener('change', syncScoringAvailability);
        syncScoringAvailability();
    })();
</script>

<div class="form-check">
    <input type="hidden" name="next_of_kin_informed" value="0">
    <input class="form-check-input" type="checkbox" value="1" id="next_of_kin_informed" name="next_of_kin_informed" {{ (int) $kinInformed === 1 ? 'checked' : '' }}>
    <label class="form-check-label" for="next_of_kin_informed" style="font-size: 13px;">
        The member confirms they have told their next of kin (and guarantor, if any) that they gave their details.
    </label>
</div>
