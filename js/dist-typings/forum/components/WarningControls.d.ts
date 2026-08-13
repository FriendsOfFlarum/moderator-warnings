import ItemList from 'flarum/common/utils/ItemList';
import type Warning from '../model/Warning';
import type Mithril from 'mithril';
/**
 * The component the controls are rendered under, exposing the callbacks the
 * actions report back through.
 */
export interface WarningControlsContext {
    loading?: boolean;
    attrs: {
        ondelete?: (warning: Warning) => void;
        onchange?: (warning: Warning) => void;
    };
}
/**
 * The `WarningControls` utility constructs a list of buttons for a warning which
 * perform actions on it.
 */
declare const _default: {
    /**
     * Get a list of controls for a warning.
     *
     * @param context The parent component under which the controls menu will
     *     be displayed.
     */
    controls(warning: Warning, context: WarningControlsContext): ItemList<Mithril.Children>;
    /**
     * Get controls for a warning pertaining to the current user (e.g. report).
     */
    userControls(_warning: Warning, _context: WarningControlsContext): ItemList<Mithril.Children>;
    /**
     * Get controls for a warning pertaining to moderation (e.g. edit).
     */
    moderationControls(_warning: Warning, _context: WarningControlsContext): ItemList<Mithril.Children>;
    /**
     * Get controls for a warning that are destructive (e.g. delete).
     */
    destructiveControls(warning: Warning, context: WarningControlsContext): ItemList<Mithril.Children>;
    /**
     * Hide a warning.
     */
    hideAction(this: Warning, context?: WarningControlsContext): Promise<void>;
    /**
     * Restore a warning.
     */
    restoreAction(this: Warning, context?: WarningControlsContext): Promise<void>;
    /**
     * Delete a warning.
     */
    deleteAction(this: Warning, context?: WarningControlsContext): Promise<void>;
};
export default _default;
