document.addEventListener('DOMContentLoaded', function () {
    var nav = document.querySelector('.bottom-nav');
    var fab = document.querySelector('.bottom-nav a.nav-fab');
    var fabIcone = fab ? fab.querySelector('i') : null;
    if (!nav || !fab) return;

    var navRect = nav.getBoundingClientRect();
    var fabRect = fab.getBoundingClientRect();
    var iconeRect = fabIcone ? fabIcone.getBoundingClientRect() : null;
    var estiloNav = getComputedStyle(nav);
    var estiloFab = getComputedStyle(fab);

    var vv = window.visualViewport;
    var centroFab = fabRect.left + fabRect.width / 2;
    var centroViewport = window.innerWidth / 2;

    var linhas = [
        'window.innerWidth: ' + window.innerWidth,
        'window.innerHeight: ' + window.innerHeight,
        'devicePixelRatio: ' + window.devicePixelRatio,
        'visualViewport width: ' + (vv ? vv.width.toFixed(1) : 'n/a'),
        'visualViewport offsetLeft: ' + (vv ? vv.offsetLeft.toFixed(1) : 'n/a'),
        '---',
        'nav left/width/right: ' + navRect.left.toFixed(1) + ' / ' + navRect.width.toFixed(1) + ' / ' + navRect.right.toFixed(1),
        'nav computed left/right/width: ' + estiloNav.left + ' / ' + estiloNav.right + ' / ' + estiloNav.width,
        'nav computed transform: ' + estiloNav.transform,
        '---',
        'fab left/width/right: ' + fabRect.left.toFixed(1) + ' / ' + fabRect.width.toFixed(1) + ' / ' + fabRect.right.toFixed(1),
        'fab computed margin: ' + estiloFab.marginLeft + ' / ' + estiloFab.marginRight,
        'fab computed transform: ' + estiloFab.transform,
        '---',
        'centro do FAB: ' + centroFab.toFixed(1),
        'centro da viewport: ' + centroViewport.toFixed(1),
        'DIFERENCA (fab - viewport): ' + (centroFab - centroViewport).toFixed(1) + ' px',
        '---',
        'icone rect: ' + (iconeRect ? iconeRect.left.toFixed(1) + ',' + iconeRect.top.toFixed(1) + ' ' + iconeRect.width.toFixed(1) + 'x' + iconeRect.height.toFixed(1) : 'n/a'),
    ];

    var caixa = document.createElement('div');
    caixa.id = 'diag-box';
    caixa.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#000;color:#0f0;' +
        'font-family:monospace;font-size:11px;line-height:1.5;padding:10px;white-space:pre-wrap;' +
        'max-height:60vh;overflow-y:auto;';
    caixa.textContent = linhas.join('\n');
    document.body.appendChild(caixa);

    // Marca visual no centro exato da viewport e no centro do FAB, pra comparar.
    var marcaViewport = document.createElement('div');
    marcaViewport.style.cssText = 'position:fixed;left:' + centroViewport + 'px;top:0;bottom:0;width:2px;background:lime;z-index:99998;';
    document.body.appendChild(marcaViewport);

    var marcaFab = document.createElement('div');
    marcaFab.style.cssText = 'position:fixed;left:' + centroFab + 'px;top:0;bottom:0;width:2px;background:magenta;z-index:99998;';
    document.body.appendChild(marcaFab);
});
