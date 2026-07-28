/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2026 Printess GmbH & Co. Kg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * Printess GmbH & Co. Kg does not provide any support for this plugin.
 */

import PrintessDesignNowPlugin from './printess-design-now/printess-design-now.plugin';
import PrintessSlimUiPlugin from './printess-slim-ui/printess-slim-ui.plugin';
import PrintessCartItemEditorPlugin from './printess-cart-item-editor/printess-cart-item-editor.plugin';
import PrintessSlimUiCartItemEditorPlugin from './printess-slim-ui-cart-item-editor/printess-slim-ui-cart-item-editor.plugin';
import PrintessSavedDesignResumePlugin from './printess-saved-design-resume/printess-saved-design-resume.plugin';
import PrintessSavedDesignsSearchPlugin from './printess-saved-designs-search/printess-saved-designs-search.plugin';
import PrintessSavedDesignAdminEditorPlugin from './printess-saved-design-admin-editor/printess-saved-design-admin-editor.plugin';

const PluginManager = window.PluginManager;

PluginManager.register('PrintessDesignNow', PrintessDesignNowPlugin, '[data-printess-design-now]');
PluginManager.register('PrintessSlimUi', PrintessSlimUiPlugin, '[data-printess-slim-ui]');
PluginManager.register('PrintessCartItemEditor', PrintessCartItemEditorPlugin, '[data-printess-cart-item-editor]');
PluginManager.register('PrintessSlimUiCartItemEditor', PrintessSlimUiCartItemEditorPlugin, '[data-printess-slim-ui-cart-item-editor]');
PluginManager.register('PrintessSavedDesignResume', PrintessSavedDesignResumePlugin, '[data-printess-saved-design-resume]');
PluginManager.register('PrintessSavedDesignsSearch', PrintessSavedDesignsSearchPlugin, '[data-printess-saved-designs-search]');
PluginManager.register('PrintessSavedDesignAdminEditor', PrintessSavedDesignAdminEditorPlugin, '[data-printess-saved-design-admin-editor]');
