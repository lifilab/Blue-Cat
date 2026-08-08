"use client";

import { AnimatePresence, motion, useReducedMotion } from "framer-motion";
import { ChevronLeft, ChevronRight, MousePointerClick, RotateCcw } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import type { Tutorial } from "@/config/tutorials";

export function TutorialPlayer({ tutorial }: { tutorial: Tutorial }) {
  const [index, setIndex] = useState(0);
  const reduceMotion = useReducedMotion();
  const step = tutorial.steps[index];
  const go = useCallback((next: number) => setIndex(Math.min(Math.max(next, 0), tutorial.steps.length - 1)), [tutorial.steps.length]);
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "ArrowRight") go(index + 1);
      if (event.key === "ArrowLeft") go(index - 1);
      if (event.key === "Home") go(0);
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [go, index]);
  return <div className="tutorial-layout">
    <section className="tutorial-stage" aria-live="polite">
      <div className="progress" aria-label={`Progreso ${index + 1} de ${tutorial.steps.length}`}><span style={{width:`${((index+1)/tutorial.steps.length)*100}%`}}/></div>
      <AnimatePresence mode="wait" initial={false}><motion.div key={step.id} initial={reduceMotion?false:{opacity:0,x:24}} animate={{opacity:1,x:0}} exit={reduceMotion?undefined:{opacity:0,x:-20}} transition={{duration:.24}}>
        <div className="tutorial-visual"><motion.div className="tutorial-cue" animate={reduceMotion?undefined:{scale:[1,1.06,1]}} transition={{repeat:Infinity,duration:2}}><MousePointerClick size={34}/></motion.div></div>
        <span className="plan-label">Paso {index+1} de {tutorial.steps.length}</span><h2>{step.title}</h2><p className="muted">{step.description}</p>
      </motion.div></AnimatePresence>
      <div className="tutorial-controls"><button className="button button-secondary" onClick={()=>go(index-1)} disabled={index===0}><ChevronLeft size={17}/> Anterior</button><button className="button button-primary" onClick={()=>go(index+1)} disabled={index===tutorial.steps.length-1}>Siguiente <ChevronRight size={17}/></button><button className="button button-secondary" onClick={()=>go(0)}><RotateCcw size={16}/> Reiniciar</button></div>
    </section>
    <nav className="step-list" aria-label="Pasos del tutorial">{tutorial.steps.map((item,itemIndex)=><button type="button" className={itemIndex===index?"active":""} aria-current={itemIndex===index?"step":undefined} key={item.id} onClick={()=>go(itemIndex)}>{itemIndex+1}. {item.title}</button>)}</nav>
  </div>;
}
