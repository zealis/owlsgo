/**
 * owlsgo-demo 插件脚本
 *
 * 由 PluginManager::mergeAssets() 合并进 /plugin-assets/js 后以 type="module" 加载。
 * 页面上的插件配置由 footer_assets 钩子以 `window.OwlsgoDemoPlugin` 注入。
 *
 * 约定：模块脚本不要污染全局，只在显式需要时挂载一个命名空间对象。
 */
(() => {
    'use strict';

    const config = window.OwlsgoDemoPlugin || {};
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** 对外暴露的插件 API，便于控制台调试或其它脚本调用 */
    window.OwlsgoDemo = {
        version: '1.0.0',
        config,
        greet(name) {
            const greeting = typeof config.greeting === 'string' ? config.greeting : '你好';

            return `${greeting} ${name || ''}`.trim();
        },
    };

    console.info('[owlsgo-demo] 插件脚本已加载', config);

    /**
     * 给标记了 data-owlsgo-demo-greeting 的元素加打字机效果，并附上样式来源角标。
     * 只处理「没有子元素」的纯文本节点，避免破坏模板里的富结构。
     */
    document.querySelectorAll('[data-owlsgo-demo-greeting]').forEach((el) => {
        if (el.children.length > 0) {
            return;
        }

        const text = (el.textContent || '').trim();

        if (text === '') {
            return;
        }

        const badge = document.createElement('span');
        badge.className = 'owlsgo-demo-badge';
        badge.textContent = `owlsgo-demo · ${config.style === 'plain' ? '极简式' : '卡片式'}`;
        badge.title = '该角标由插件脚本动态插入';

        const textNode = document.createTextNode('');
        el.textContent = '';
        el.append(textNode, badge);

        if (reduceMotion) {
            textNode.textContent = text;
            return;
        }

        el.classList.add('owlsgo-demo-typing');

        let index = 0;
        const step = () => {
            index += 1;
            textNode.textContent = text.slice(0, index);

            if (index < text.length) {
                window.setTimeout(step, 45);
            } else {
                el.classList.remove('owlsgo-demo-typing');
            }
        };

        window.setTimeout(step, 120);
    });
})();
