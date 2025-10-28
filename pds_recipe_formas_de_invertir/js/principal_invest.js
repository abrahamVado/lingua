// js/principal_invest.js
(function(){
  const slider=document.querySelector('.principal_invest__slider'); if(!slider) return;
  const track=slider.querySelector('.principal_invest__track');
  const prev=slider.querySelector('.principal_invest__nav--prev');
  const next=slider.querySelector('.principal_invest__nav--next');
  const step=()=>track.clientWidth;
  function update(){const max=track.scrollWidth-track.clientWidth-1; prev.disabled=track.scrollLeft<=0; next.disabled=track.scrollLeft>=max;}
  function go(d){track.scrollBy({left:d*step(),behavior:'smooth'}); setTimeout(update,350);}
  prev.addEventListener('click',()=>go(-1));
  next.addEventListener('click',()=>go(1));
  track.addEventListener('scroll',update,{passive:true});
  window.addEventListener('resize',update);
  slider.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'){e.preventDefault();go(-1)} if(e.key==='ArrowRight'){e.preventDefault();go(1)}});
  update();
})();
