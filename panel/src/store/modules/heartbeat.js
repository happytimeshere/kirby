import Vue from "vue";

export default {
  namespaced: true,
  state: {
    interval: null,
    beats: []
  },
  mutations: {
    ADD(state, beat) {
      state.beats.push(beat);
    },
    CLEAR(state) {
      clearInterval(state.interval);
    },
    REMOVE(state, beat) {
      const index = state.beats.indexOf(beat);
      if (index !== -1) {
        Vue.delete(state.beats, index);
      }
    },
    RUN(state) {
      clearInterval(state.interval);
      state.interval = setInterval(() => {
        state.beats.forEach(beat => {
          beat();
        });
      }, 15 * 1000);
    }
  },
  actions: {
    add(context, beat) {
      beat();
      context.commit("ADD", beat);

      if (context.state.beats.length === 1) {
        context.commit("CLEAR");
        context.commit("RUN");
      }
    },
    remove(context, beat) {
      context.commit("REMOVE", beat);

      if (context.state.beats.length < 1) {
        context.commit("CLEAR");
      }
    }
  }
};
