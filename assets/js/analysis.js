(function(){
    'use strict';
    function init(){
        var btn=document.getElementById('aiwp-run-analysis');
        if(btn)btn.addEventListener('click',runAnalysis);
    }
    function runAnalysis(){
        var btn=document.getElementById('aiwp-run-analysis');
        btn.disabled=true;btn.textContent='⏳ Анализ...';
        var d=new FormData();d.append('action','aiwp_run_analysis');d.append('nonce',AIWP.nonce);
        fetch(AIWP.ajax_url,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(r){
            if(r.success)location.reload();else{btn.disabled=false;btn.textContent='❌ Ошибка';}
        }).catch(function(){btn.disabled=false;btn.textContent='❌ Error';});
    }
    document.addEventListener('DOMContentLoaded',init);
})();
