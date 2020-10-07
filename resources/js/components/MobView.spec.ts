import {mount, Wrapper} from "@vue/test-utils";
import MobView from "./MobView.vue";
import userJson from '../../../tests/fixtures/User.json';
import {User} from "../classes/User";

const testMob = {
    id: 123,
    topic: 'test',
    location: 'test',
    date: '01/01/2020',
    start_time: new Date().toDateString(),
    end_time: new Date().toDateString(),
    owner: userJson,
    attendees: [
        {
            avatar: 'lastAirBender.jpg',
            email: 'jack@bauer.com',
            id: 987,
            name: 'Cat',
            username: 'cat'
        },
        {
            avatar: 'lastAirBender.jpg',
            email: 'jack@bauer.com',
            id: 986,
            name: 'Dog',
            username: 'dog'
        }
    ],
    comments: [],
}

describe('MobView', () => {
    let wrapper: Wrapper<MobView>;

    beforeEach(() => {
        wrapper = mount(MobView, {propsData: {user: userJson, mobJson: testMob}});
    });

    it('redirects to the owners GitHub page when clicked on the profile', async () => {
        const ownerComponent = wrapper.findComponent({ref: 'owner-avatar-link'})

        expect(ownerComponent.element).toHaveAttribute('href', new User(testMob.owner).githubURL)
    })

    describe('attendees section', () => {
        it('redirects to the attendees GitHub page when clicked on the profile', async () => {
            const attendeeComponents = wrapper.findAllComponents({ref: 'attendee'})

            attendeeComponents.wrappers.forEach((attendeeComponent, i) =>
                expect(attendeeComponent.element).toHaveAttribute('href', new User(testMob.attendees[i]).githubURL)
            )
        })
    })
})