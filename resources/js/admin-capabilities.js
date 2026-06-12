export function adminCapabilityEditor(config = {}) {
    const initialSections = config.sections || {};
    const presets = config.presets || {};

    return {
        sections: { ...initialSections },
        presets,

        sectionCount() {
            return Object.values(this.sections).filter(Boolean).length;
        },

        totalSections() {
            return Object.keys(this.sections).length;
        },

        applyPreset(name) {
            const preset = this.presets[name] || {};

            this.setSections(preset);
        },

        selectAllSections() {
            this.setSections(Object.fromEntries(
                Object.keys(this.sections).map((section) => [section, true])
            ));
        },

        setSections(nextSections) {
            Object.keys(this.sections).forEach((section) => {
                this.sections[section] = Boolean(nextSections[section]);
            });
        },
    };
}
